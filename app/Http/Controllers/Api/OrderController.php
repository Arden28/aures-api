<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\Order\OrderCreated;
use App\Events\Order\OrderItemStatusUpdated;
use App\Events\Order\OrderStatusUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderRequest;
use App\Http\Requests\Order\UpdateOrderItemStatusRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Display a paginated list of orders for the authenticated restaurant.
     */
    public function index(Request $request): JsonResponse
    {
        $user         = $request->user();
        $restaurantId = $user->restaurant_id;

        $query = Order::with(['items.product', 'table', 'waiter', 'transactions'])
            ->where('restaurant_id', $restaurantId)
            ->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = (int) $request->query('per_page', 20);

        $orders = $query->paginate($perPage);

        return response()->json(OrderResource::collection($orders));
    }

    /**
     * Store a new order along with its order items (atomic).
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $user       = $request->user();
        $restaurant = $user->restaurant;
        $validated  = $request->validated();

        $itemsData = $validated['items'];

        $productMap = Product::where('restaurant_id', $restaurant->id)
            ->whereIn('id', collect($itemsData)->pluck('product_id'))
            ->get()
            ->keyBy('id');

        if ($productMap->count() !== count($itemsData)) {
            return response()->json([
                'message' => 'One or more products are invalid for this restaurant.',
            ], 422);
        }

        $order = DB::transaction(function () use ($validated, $itemsData, $productMap, $restaurant, $user) {
            // ... existing order creation logic ...
            // return $order;
            $order = new Order();

            $order->restaurant_id  = $restaurant->id;
            $order->table_id       = $validated['table_id'] ?? null;
            $order->client_id      = $validated['client_id'] ?? null;
            $order->waiter_id      = $user->id;
            $order->status         = OrderStatus::PENDING;
            $order->source         = $validated['source'] ?? 'waiter';
            $order->opened_at      = now();
            $order->payment_status = PaymentStatus::UNPAID;

            $order->save();

            $subtotal = 0;

            foreach ($itemsData as $itemRequest) {
                $product = $productMap[$itemRequest['product_id']];
                $qty     = (int) $itemRequest['quantity'];
                $unit    = (float) $product->price;
                $total   = $unit * $qty;

                $subtotal += $total;

                $order->items()->create([
                    'product_id'  => $product->id,
                    'quantity'    => $qty,
                    'unit_price'  => $unit,
                    'total_price' => $total,
                    'status'      => OrderItemStatus::PENDING,
                    'notes'       => $itemRequest['notes'] ?? null,
                ]);
            }

            $taxRate        = (float) ($restaurant->tax_rate ?? 0);
            $serviceRate    = (float) ($restaurant->service_charge_rate ?? 0);
            $taxAmount      = round($subtotal * $taxRate / 100, 2);
            $serviceCharge  = round($subtotal * $serviceRate / 100, 2);
            $discountAmount = (float) ($validated['discount_amount'] ?? 0);
            $total          = max(0, $subtotal + $taxAmount + $serviceCharge - $discountAmount);

            $order->subtotal        = $subtotal;
            $order->tax_amount      = $taxAmount;
            $order->service_charge  = $serviceCharge;
            $order->discount_amount = $discountAmount;
            $order->total           = $total;
            $order->paid_amount     = 0;

            $order->save();

            return $order;
        });

        // Ensure we broadcast AFTER commit
        $order->load(['items.product', 'table', 'waiter']);
        event(new OrderCreated($order));

        return response()->json([
            'message' => 'Order created successfully.',
            'data'    => new OrderResource($order),
        ], 201);
    }


    /**
     * Display a specific order (scoped to restaurant).
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeRestaurant($request, $order);

        $order->load(['items.product', 'table', 'waiter', 'transactions']);

        return response()->json(new OrderResource($order));
    }

    /**
     * Update order metadata (not status or payment).
     */
    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $this->authorizeRestaurant($request, $order);

        $order->update($request->validated());

        return response()->json([
            'message' => 'Order updated successfully.',
            'data'    => new OrderResource($order->fresh('items.product', 'table', 'waiter')),
        ]);
    }

    /**
     * Delete an order.
     */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->authorizeRestaurant($request, $order);

        $order->delete();

        return response()->json([
            'message' => 'Order deleted successfully.',
        ]);
    }

    /* =========================================================================
     |                       ORDER STATUS UPDATE (State Machine)
     ========================================================================= */

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorizeRestaurant($request, $order);

        $newStatus     = OrderStatus::from($request->status);
        $currentStatus = $order->status;

        if (! $this->canTransitionOrder($currentStatus, $newStatus)) {
            return response()->json([
                'message' => "Invalid transition: cannot move order from '{$currentStatus->value}' to '{$newStatus->value}'.",
            ], 422);
        }

        $order->status = $newStatus;

        if (in_array($newStatus, [OrderStatus::COMPLETED, OrderStatus::CANCELLED], true)) {
            $order->closed_at = now();
        }

        $order->save();

        // broadcast
        event(new OrderStatusUpdated($order, $currentStatus->value, $newStatus->value));

        return response()->json([
            'message' => 'Order status updated.',
            'data'    => [
                'old_status' => $currentStatus->value,
                'new_status' => $order->status->value,
            ],
        ]);
    }


    protected function canTransitionOrder(OrderStatus $from, OrderStatus $to): bool
    {
        $allowedTransitions = [
            OrderStatus::PENDING->value      => [OrderStatus::IN_PROGRESS, OrderStatus::CANCELLED],
            OrderStatus::IN_PROGRESS->value  => [OrderStatus::READY, OrderStatus::CANCELLED],
            OrderStatus::READY->value        => [OrderStatus::SERVED],
            OrderStatus::SERVED->value       => [OrderStatus::COMPLETED],
        ];

        return in_array(
            $to->value,
            $allowedTransitions[$from->value] ?? [],
            true
        );
    }

    /* =========================================================================
     |                 ORDER ITEM STATUS UPDATE (KDS State Machine)
     ========================================================================= */

    public function updateItemStatus(UpdateOrderItemStatusRequest $request, OrderItem $item): JsonResponse
    {
        $order = $item->order;

        $this->authorizeRestaurant($request, $order);

        $newStatus     = OrderItemStatus::from($request->status);
        $currentStatus = $item->status;

        if (! $this->canTransitionItem($currentStatus, $newStatus)) {
            return response()->json([
                'message' => "Invalid transition: cannot move order item from '{$currentStatus->value}' to '{$newStatus->value}'.",
            ], 422);
        }

        $item->status = $newStatus;
        $item->save();

        // broadcast
        event(new OrderItemStatusUpdated($item, $currentStatus->value, $newStatus->value));

        return response()->json([
            'message' => 'Order item status updated.',
            'data'    => [
                'old_status' => $currentStatus->value,
                'new_status' => $item->status->value,
            ],
        ]);
    }


    protected function canTransitionItem(OrderItemStatus $from, OrderItemStatus $to): bool
    {
        $allowedTransitions = [
            OrderItemStatus::PENDING->value  => [OrderItemStatus::COOKING, OrderItemStatus::CANCELLED],
            OrderItemStatus::COOKING->value  => [OrderItemStatus::READY, OrderItemStatus::CANCELLED],
            OrderItemStatus::READY->value    => [OrderItemStatus::SERVED],
        ];

        return in_array(
            $to->value,
            $allowedTransitions[$from->value] ?? [],
            true
        );
    }

    /* =========================================================================
     |                                Helpers
     ========================================================================= */

    protected function authorizeRestaurant(Request $request, Order $order): void
    {
        if ($request->user()->restaurant_id !== $order->restaurant_id) {
            abort(403, 'Forbidden for this restaurant.');
        }
    }
}
