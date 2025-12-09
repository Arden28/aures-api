<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\Order\OrderCreated;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PortalController extends Controller
{
    /**
     * Public portal entrypoint.
     *
     * - Resolves table by QR code.
     * - Loads today's active order (if any).
     * - Returns menu (categories + products) and current order snapshot.
     */
    public function index(string $code)
    {
        $table = Table::where('code', $code)
            ->with('restaurant')
            ->first();

        if (! $table) {
            return response()->json(['message' => 'Invalid table code'], 404);
        }

        // Use startOfDay to scope orders to "today" for this table.
        $today = now()->startOfDay();

        $activeOrder = Order::where('table_id', $table->id)
            // Active lifecycle for the customer-facing portal
            ->whereIn('status', [
                OrderStatus::PENDING,
                OrderStatus::PREPARING,
                OrderStatus::READY,
                OrderStatus::SERVED,
            ])
            // Only consider orders opened today
            ->where('created_at', '>=', $today)
            ->with(['items.product'])
            ->latest()
            ->first();

        // Load menu for this restaurant (only available products)
        $categories = Category::where('restaurant_id', $table->restaurant_id)
            ->with(['products' => fn ($query) => $query->where('is_available', true)])
            ->get();

        $mappedCategories = $categories->map(fn ($c) => [
            'id'   => $c->id,
            'name' => $c->name,
            'slug' => str()->slug($c->name),
        ]);

        $mappedProducts = $categories->flatMap(function ($cat) {
            return $cat->products->map(function ($prod) use ($cat) {
                return [
                    'id'          => $prod->id,
                    'category_id' => $cat->id,
                    'name'        => $prod->name,
                    'description' => $prod->description,
                    'price'       => (float) $prod->price,
                    'image'       => $prod->image_path
                        ? Storage::disk('public')->url($prod->image_path)
                        : null,
                    'is_available' => (bool) $prod->is_available,
                    'is_popular'   => false, // TODO: derive from stats (top sellers, etc.)
                ];
            });
        })->values();

        // Normalize active order snapshot for the frontend
        $formattedOrder = null;
        if ($activeOrder) {
            $formattedOrder = [
                'id'           => $activeOrder->id,
                'status'       => $activeOrder->status,
                'estimatedTime'=> '15-20 mins',
                'timestamp'    => $activeOrder->created_at->timestamp * 1000,
                'items'        => $activeOrder->items->map(function ($item) {
                    return [
                        'order_item_id' => $item->id,
                        'product'       => [
                            'id'          => $item->product_id,
                            'name'        => $item->product->name,
                            'price'       => (float) $item->unit_price,
                            'description' => $item->product->description,
                            'image'       => $item->product->image_path
                                ? Storage::disk('public')->url($item->product->image_path)
                                : null,
                        ],
                        'quantity' => $item->quantity,
                        'notes'    => $item->notes,
                        'status'   => $item->status,
                        'tempId'   => 'existing-' . $item->id,
                    ];
                }),
            ];
        }

        return response()->json([
            'session' => [
                'id'              => session()->getId(),
                'table_name'      => $table->name,
                'restaurant_name' => $table->restaurant->name,
                'currency'        => $table->restaurant->currency ?? 'USD',
            ],
            'menu'         => [
                'categories' => $mappedCategories,
                'products'   => $mappedProducts,
            ],
            'active_order' => $formattedOrder,
        ]);
    }

    /**
     * Create a new order for a table.
     *
     * - Prevents opening a second active order on the same table.
     * - Delegates heavy logic to processOrderSave().
     */
    public function store(Request $request, string $code)
    {
        $table = Table::where('code', $code)->firstOrFail();

        // Basic payload validation
        $request->validate([
            'items'                 => 'required|array|min:1',
            'items.*.product.id'    => 'required|exists:products,id',
            'items.*.product.price' => 'required|numeric|min:0',
            'items.*.quantity'      => 'required|integer|min:1',
            'items.*.notes'         => 'nullable|string',
        ]);

        // Ensure there is no other active order for that table
        $existingOrder = Order::where('table_id', $table->id)
            ->whereIn('status', [
                OrderStatus::PENDING,
                OrderStatus::PREPARING,
                OrderStatus::READY,
            ])
            ->exists();

        if ($existingOrder) {
            return response()->json([
                'message' => 'There is already an active order for this table.',
            ], 409);
        }

        return $this->processOrderSave(new Order(), $request, $table, false);
    }

    /**
     * Update an existing order (add/remove items, change quantities/notes).
     *
     * Business rules:
     * - Closed orders (COMPLETED / CANCELLED) are immutable.
     * - Items that are not PENDING cannot be removed or have quantities decreased.
     */
    public function update(Request $request, string $code, Order $order)
    {
        // Safety check: the URL code should correspond to order's table.
        if ($order->table?->code !== $code) {
            return response()->json(['message' => 'Table mismatch for this order.'], 400);
        }

        if (in_array($order->status, [OrderStatus::COMPLETED, OrderStatus::CANCELLED], true)) {
            return response()->json(['message' => 'Cannot modify a closed order.'], 403);
        }

        $request->validate([
            'items'                 => 'required|array|min:1',
            'items.*.product.id'    => 'required|exists:products,id',
            'items.*.product.price' => 'required|numeric|min:0',
            'items.*.quantity'      => 'required|integer|min:1',
            'items.*.notes'         => 'nullable|string',
            'items.*.order_item_id' => 'nullable|integer',
        ]);

        return $this->processOrderSave($order, $request, $order->table, true);
    }

    /**
     * Core order persistence logic shared by create + update.
     *
     * Responsibilities:
     * - Create order header (when !$isUpdate).
     * - Upsert order items:
     *      - update existing items (respecting business constraints),
     *      - add new items,
     *      - delete removed items (PENDING only).
     * - Recalculate order total.
     * - Mark table as occupied.
     * - Dispatch events after successful commit.
     */
    private function processOrderSave(Order $order, Request $request, $table, bool $isUpdate = false)
    {
        try {
            $incomingItems = collect($request->items);
            $wasNewOrder   = ! $isUpdate || ! $order->exists;

            // Wrap everything in a transaction so either the whole change set passes or fails.
            $order = DB::transaction(function () use ($order, $table, $incomingItems, $isUpdate) {
                if (! $isUpdate) {
                    // Initialize a brand-new order
                    $order->fill([
                        'restaurant_id'  => $table->restaurant_id,
                        'table_id'       => $table->id,
                        'status'         => OrderStatus::PENDING,
                        'payment_status' => PaymentStatus::UNPAID,
                        'source'         => 'portal',
                        'total'          => 0,
                        'opened_at'      => now(),
                    ])->save();
                }

                /** @var \Illuminate\Support\Collection<int,\App\Models\OrderItem> $existingItems */
                $existingItems = $isUpdate ? $order->items : collect();

                $total = 0;

                // 1. Upsert incoming items
                foreach ($incomingItems as $itemData) {
                    $prodId      = $itemData['product']['id'];
                    $qty         = $itemData['quantity'];
                    $price       = $itemData['product']['price'];
                    $notes       = $itemData['notes'] ?? null;
                    $orderItemId = $itemData['order_item_id'] ?? null;

                    if ($orderItemId) {
                        // Update existing order item
                        /** @var \App\Models\OrderItem|null $existingItem */
                        $existingItem = $existingItems->find($orderItemId);

                        if ($existingItem) {
                            // Constraint: cannot decrease quantity for non-pending items
                            if (
                                $existingItem->status !== OrderItemStatus::PENDING
                                && $qty < $existingItem->quantity
                            ) {
                                throw new \RuntimeException(
                                    "Cannot remove items that are already preparing."
                                );
                            }

                            $existingItem->update([
                                'quantity'    => $qty,
                                'notes'       => $notes,
                                'total_price' => $price * $qty,
                            ]);

                            $total += $price * $qty;
                        }
                    } else {
                        // Create new order item
                        OrderItem::create([
                            'order_id'    => $order->id,
                            'product_id'  => $prodId,
                            'quantity'    => $qty,
                            'unit_price'  => $price,
                            'total_price' => $price * $qty,
                            'notes'       => $notes,
                            'status'      => OrderItemStatus::PENDING,
                        ]);

                        $total += $price * $qty;
                    }
                }

                // 2. Handle removals (only when updating)
                if ($isUpdate) {
                    $incomingIds   = $incomingItems->pluck('order_item_id')->filter()->toArray();
                    $itemsToDelete = $existingItems->whereNotIn('id', $incomingIds);

                    foreach ($itemsToDelete as $itemToDelete) {
                        // Constraint: Only PENDING items can be removed entirely
                        if ($itemToDelete->status !== OrderItemStatus::PENDING) {
                            throw new \RuntimeException(
                                "Cannot remove '{$itemToDelete->product->name}' as it is already being prepared."
                            );
                        }

                        $itemToDelete->delete();
                    }
                }

                // 3. Update order header with new total
                $order->update([
                    'total'      => $total,
                    'updated_at' => now(), // not strictly necessary; Eloquent handles timestamps
                ]);

                // 4. Ensure table is flagged as occupied
                $table->update(['status' => 'occupied']);

                // Return the instance to the outer scope (with updated state)
                return $order;
            });

            // === BROADCASTING / EVENTS ======================================
            // We dispatch events *after* the transaction to avoid broadcasting inconsistent state.

            if ($wasNewOrder) {
                // Load relationships needed by OrderResource for the broadcast payload
                $order->loadMissing(['items.product', 'table', 'waiter']);

                event(new OrderCreated($order));
            } else {
                // Optional: you might want to broadcast an "OrderUpdated" type event here.
                // e.g. event(new OrderUpdated($order->loadMissing(['items.product', 'table', 'waiter'])));
            }

            return response()->json([
                'id'            => $order->id,
                'status'        => $order->status,
                'estimatedTime' => '15-20 mins',
                'timestamp'     => now()->timestamp * 1000,
            ]);
        } catch (Throwable $e) {
            // Let the client know why the request failed.
            // You may want to log the error for internal debugging.
            report($e);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
