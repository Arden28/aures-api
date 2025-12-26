<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
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
use App\Models\TableSession;
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

        // Define the daily scope once
        $startOfDay = now()->startOfDay();
        $endOfDay   = now()->endOfDay();

        // -------------------------------------------------------------
        // Unify daily filtering for operational roles
        // -------------------------------------------------------------

        // Owner/Manager sees ALL orders (no daily filter)
        if ($user->role !== UserRole::OWNER && $user->role !== UserRole::MANAGER) {
            // Waiter, Kitchen, etc., only see orders opened today
            $query->whereBetween('opened_at', [
                $startOfDay,
                $endOfDay
            ]);
        }

        // Kitchen sees items that need to be prepared/served.
        // It needs a special filter to show ONLY items in PENDING/PREPARING/READY states.
        if ($user->role === UserRole::KITCHEN) {
            // REMOVED: The created_at filter is redundant and inaccurate.
            // We rely on the opened_at filter above AND the status filter below.

            // To be truly useful, the kitchen needs to see pending/preparing/ready orders.
            // If the request doesn't specify a status, we default to the KDS view.
             if (! $request->query('status')) {
                 $query->whereIn('status', [
                     OrderStatus::PENDING,
                     OrderStatus::PREPARING,
                     OrderStatus::READY
                 ]);
             }
        }

        // Waiter sees their own orders + unassigned orders opened today.
        if ($user->role === UserRole::WAITER) {
            // IMPROVEMENT: Combine both waiter filters correctly with a callback
            $query->where(function ($q) use ($user) {
                $q->where('waiter_id', $user->id)
                  ->orWhereNull('waiter_id');
            });
        }

        // FIX: Handle array of statuses for KDS filtering
        if ($status = $request->query('status')) {
            $statuses = is_array($status) ? $status : explode(',', $status);
            $query->whereIn('status', $statuses);
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

            // Initiate status history
            $order->recordStatusChange(OrderStatus::PENDING, $user->id);

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

    /**
     * Update the status of an existing order.
     * Handles transitions (e.g. pending -> preparing), waiter assignment,
     * and automatic session status updates.
     */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        // 1. Authorization: Ensure user owns this restaurant resource
        $this->authorizeRestaurant($request, $order);

        $newStatus     = OrderStatus::from($request->status);
        $currentStatus = $order->status;

        // 2. Validation: Check if this state change is allowed
        if (! $this->canTransitionOrder($currentStatus, $newStatus)) {
            return response()->json([
                'message' => "Invalid transition: cannot move order from '{$currentStatus->value}' to '{$newStatus->value}'.",
            ], 422);
        }

        // 3. Validation: Check for Waiter Conflict (Race Condition Protection)
        // If a waiter_id is being sent, ensures it hasn't been snagged by someone else already.
        if ($request->filled('waiter_id')) {
            if ($order->waiter_id !== null && $order->waiter_id != $request->waiter_id) {
                return response()->json([
                    'message' => 'This order has already been claimed by another waiter.',
                    'claimed_by' => $order->waiter?->name // Optional: helpful for UI
                ], 409); // 409 Conflict
            }
        }

        // 4. Update Logic (Wrapped in Transaction for data integrity)
        DB::transaction(function () use ($order, $newStatus, $request, $currentStatus) {

            // A. Update Status
            $order->status = $newStatus;

            // Record status change in history
            $order->recordStatusChange($newStatus, $request->user()->id);

            // B. Set Closing Timestamp if completed/cancelled
            if (in_array($newStatus, [OrderStatus::COMPLETED, OrderStatus::CANCELLED], true)) {
                $order->closed_at = now();
            }

            // C. Handle Waiter Assignment (Claiming)
            if ($request->filled('waiter_id')) {
                // We already validated conflicts above, so we can safely assign.
                $order->waiter_id = $request->waiter_id;

                // D. Update Session Status
                // If a waiter claims an order, the table session becomes 'active'
                $session = $this->resolveTableSession($request, $order);

                if ($session && $session->status === 'waiting-confirmation') {
                    $session->update(['status' => 'active']);
                }
            }

            $order->save();

            // E. Broadcast Event (Real-time updates for KDS/Waiter Apps)
            event(new OrderStatusUpdated($order, $currentStatus->value, $newStatus->value));
        });

        return response()->json([
            'message' => 'Order status updated.',
            'data'    => [
                'order_id'   => $order->id,
                'old_status' => $currentStatus->value,
                'new_status' => $order->status->value,
                'waiter'     => $order->waiter?->name,
            ],
        ]);
    }

    /**
     * Helper to find the correct TableSession for this order.
     * Prioritizes explicit session_id, falls back to the latest open session for the table.
     */
    private function resolveTableSession($request, Order $order)
    {
        if (!$order->table_id) {
            return null; // Takeout orders might not have a table session
        }

        // 1. Try explicit ID from request
        if ($request->session_id) {
            return TableSession::where('id', $request->session_id)
                ->where('table_id', $order->table_id)
                ->where('status', '!=', 'closed')
                ->firstOrFail();
        }

        // 2. Fallback: Find latest active session for this table created today
        return TableSession::where('table_id', $order->table_id)
            ->where('status', '!=', 'closed')
            ->where('created_at', '>=', now()->startOfDay())
            ->latest()
            ->first();
    }


    protected function canTransitionOrder(OrderStatus $from, OrderStatus $to): bool
    {
        // 1. Get all statuses to determine order precedence
        // Assuming your Enum definitions are: pending, preparing, ready, served, completed, cancelled

        // Allow generic forward movement + Cancellation at any stage
        $allowedTransitions = [
            OrderStatus::PENDING->value => [
                OrderStatus::PREPARING,
                OrderStatus::READY,     // <--- Added: Allow skipping to Ready
                OrderStatus::SERVED,    // <--- Added: Allow skipping to Served
                OrderStatus::CANCELLED
            ],
            OrderStatus::PREPARING->value => [
                OrderStatus::READY,
                OrderStatus::SERVED,    // <--- Added: Allow skipping to Served
                OrderStatus::PENDING,   // <--- Added: Allow moving back (oops I started too early)
                OrderStatus::CANCELLED
            ],
            OrderStatus::READY->value => [
                OrderStatus::SERVED,
                OrderStatus::PREPARING, // <--- Added: Allow moving back (oops it's not actually ready)
                OrderStatus::COMPLETED
            ],
            OrderStatus::SERVED->value => [
                OrderStatus::COMPLETED,
                OrderStatus::READY      // <--- Added: Allow moving back
            ],
        ];

        return in_array(
            $to->value,
            // Map the Enums to their values for comparison, or ensure strict comparison works
            array_map(fn($s) => $s instanceof OrderStatus ? $s->value : $s, $allowedTransitions[$from->value] ?? []),
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
            OrderItemStatus::PENDING->value => [
                OrderItemStatus::COOKING,
                OrderItemStatus::READY,   // <--- Allow skip
                OrderItemStatus::SERVED,  // <--- Allow skip
                OrderItemStatus::CANCELLED
            ],
            OrderItemStatus::COOKING->value => [
                OrderItemStatus::READY,
                OrderItemStatus::SERVED,  // <--- Allow skip
                OrderItemStatus::PENDING, // <--- Allow back
                OrderItemStatus::CANCELLED
            ],
            OrderItemStatus::READY->value => [
                OrderItemStatus::SERVED,
                OrderItemStatus::COOKING  // <--- Allow back
            ],
        ];

        return in_array(
            $to->value,
            array_map(fn($s) => $s instanceof OrderItemStatus ? $s->value : $s, $allowedTransitions[$from->value] ?? []),
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
