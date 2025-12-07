<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PortalController extends Controller
{
    public function index($code)
    {
        $table = Table::where('code', $code)->with('restaurant')->first();

        if (!$table) {
            return response()->json(['message' => 'Invalid table code'], 404);
        }

        // Get the current date to filter orders
        $today = now()->startOfDay();

        // Check for an Active Order
        $activeOrder = Order::where('table_id', $table->id)
            // 1. Check status (PENDING, PREPARING, READY, SERVED)
            ->whereIn('status', [OrderStatus::PENDING, OrderStatus::PREPARING, OrderStatus::READY, OrderStatus::SERVED])
            // 2. Filter orders created TODAY
            ->whereDate('created_at', '>=', $today)
            ->with(['items.product'])
            ->latest()
            ->first();

        // Fetch Menu
        $categories = Category::where('restaurant_id', $table->restaurant_id)
            ->with(['products' => function ($query) {
                $query->where('is_available', true);
            }])
            ->get();

        $mappedCategories = $categories->map(fn($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => str()->slug($c->name)]);

        $mappedProducts = $categories->flatMap(function ($cat) {
            return $cat->products->map(function ($prod) use ($cat) {
                return [
                    'id' => $prod->id,
                    'category_id' => $cat->id,
                    'name' => $prod->name,
                    'description' => $prod->description,
                    'price' => (float) $prod->price,
                    'image' => $prod->image_path ? Storage::disk('public')->url($prod->image_path) : null,
                    'is_available' => (bool) $prod->is_available,
                    'is_popular' => false, // Logic for popular items can be added here
                ];
            });
        })->values();

        // Transform Active Order
        $formattedOrder = null;
        if ($activeOrder) {
            $formattedOrder = [
                'id' => $activeOrder->id,
                'status' => $activeOrder->status,
                'estimatedTime' => '15-20 mins',
                'timestamp' => $activeOrder->created_at->timestamp * 1000,
                'items' => $activeOrder->items->map(function ($item) {
                    return [
                        'order_item_id' => $item->id,
                        'product' => [
                            'id' => $item->product_id,
                            'name' => $item->product->name,
                            'price' => (float) $item->unit_price,
                            'description' => $item->product->description,
                            'image' => $item->product->image_path ? Storage::disk('public')->url($item->product->image_path) : null,
                        ],
                        'quantity' => $item->quantity,
                        'notes' => $item->notes,
                        'status' => $item->status,
                        'tempId' => 'existing-' . $item->id
                    ];
                })
            ];
        }

        return response()->json([
            'session' => [
                'id' => session()->getId(),
                'table_name' => $table->name,
                'restaurant_name' => $table->restaurant->name,
                'currency' => $table->restaurant->currency ?? 'USD',
            ],
            'menu' => ['categories' => $mappedCategories, 'products' => $mappedProducts],
            'active_order' => $formattedOrder
        ]);
    }

    public function store(Request $request, $code)
    {
        $table = Table::where('code', $code)->firstOrFail();

        $existingOrder = Order::where('table_id', $table->id)
            ->whereIn('status', [OrderStatus::PENDING, OrderStatus::PREPARING, OrderStatus::READY])
            ->exists();

        if ($existingOrder) {
            return response()->json(['message' => 'There is already an active order for this table.'], 409);
        }

        return $this->processOrderSave(new Order(), $request, $table);
    }

    public function update(Request $request, $code, Order $order)
    {
        if ($order->status === OrderStatus::COMPLETED || $order->status === OrderStatus::CANCELLED) {
            return response()->json(['message' => 'Cannot modify a closed order.'], 403);
        }

        $request->validate([
            'items' => 'required|array',
            'items.*.product.id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        return $this->processOrderSave($order, $request, $order->table, true);
    }

    private function processOrderSave(Order $order, Request $request, $table, $isUpdate = false)
    {
        try {
            DB::beginTransaction();

            if (!$isUpdate) {
                $order->fill([
                    'restaurant_id' => $table->restaurant_id,
                    'table_id' => $table->id,
                    'status' => OrderStatus::PENDING,
                    'payment_status' => PaymentStatus::UNPAID,
                    'source' => 'portal',
                    'total' => 0,
                    'opened_at' => now(),
                ])->save();
            }

            $incomingItems = collect($request->items);
            $existingItems = $isUpdate ? $order->items : collect([]);

            $total = 0;

            // 1. Process Incoming Items
            foreach ($incomingItems as $itemData) {
                $prodId = $itemData['product']['id'];
                $qty = $itemData['quantity'];
                $price = $itemData['product']['price'];
                $notes = $itemData['notes'] ?? null;
                $orderItemId = $itemData['order_item_id'] ?? null;

                if ($orderItemId) {
                    $existingItem = $existingItems->find($orderItemId);
                    if ($existingItem) {
                        // CONSTRAINT: Strict check - only PENDING items can be modified in quantity if reducing
                        if ($existingItem->status !== OrderItemStatus::PENDING && $qty < $existingItem->quantity) {
                            throw new \Exception("Cannot remove items that are already preparing.");
                        }

                        $existingItem->update([
                            'quantity' => $qty,
                            'notes' => $notes,
                            'total_price' => $price * $qty
                        ]);
                        $total += ($price * $qty);
                    }
                } else {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $prodId,
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'total_price' => $price * $qty,
                        'notes' => $notes,
                        'status' => OrderItemStatus::PENDING
                    ]);
                    $total += ($price * $qty);
                }
            }

            // 2. Handle Removals
            if ($isUpdate) {
                $incomingIds = $incomingItems->pluck('order_item_id')->filter()->toArray();
                $itemsToDelete = $existingItems->whereNotIn('id', $incomingIds);

                foreach ($itemsToDelete as $itemToDelete) {
                    // CONSTRAINT: Cannot delete if not pending
                    if ($itemToDelete->status !== OrderItemStatus::PENDING) {
                        throw new \Exception("Cannot remove '{$itemToDelete->product->name}' as it is already being prepared.");
                    }
                    $itemToDelete->delete();
                }
            }

            $order->update([
                'total' => $total,
                'updated_at' => now()
            ]);

            $table->update(['status' => 'occupied']);

            DB::commit();

            return response()->json([
                'id' => $order->id,
                'status' => $order->status,
                'estimatedTime' => '15-20 mins',
                'timestamp' => now()->timestamp * 1000
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
