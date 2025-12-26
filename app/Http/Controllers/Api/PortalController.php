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
use App\Models\TableSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PortalController extends Controller
{
    /**
     * GET /api/v1/portal/{code}
     * * Loads the initial state for the guest scanning the QR code.
     * Returns: Restaurant Info, Menu, and the Active Session (if any).
     */
    public function index(Request $request, string $code): JsonResponse
    {
        // 1. Validate Table Code
        $table = Table::where('code', $code)->with(['restaurant'])->first();

        if (! $table) {
            return response()->json(['message' => 'Invalid table code'], 404);
        }

        // 2. Resolve Active Session
        // We look for a session that is NOT closed and was created today.
        $startOfDay = now()->startOfDay();

        $activeSession = TableSession::where('table_id', $table->id)
            ->where('status', '!=', 'closed')
            ->where('created_at', '>=', $startOfDay)
            ->with(['orders' => function ($query) {
                // Eager load orders relevant to the guest (Tracker View).
                // We include COMPLETED so they can see paid history.
                $query->whereIn('status', [
                    OrderStatus::PENDING,
                    OrderStatus::PREPARING,
                    OrderStatus::READY,
                    OrderStatus::SERVED,
                    OrderStatus::COMPLETED // Added so paid orders don't disappear
                ])
                ->with('items.product')
                ->latest();
            }])
            ->latest()
            ->first();

        // 3. Security Check
        // If there is an active session, and the incoming device_id doesn't match the session's device_id,
        // BLOCK access to the session data.
        if ($activeSession && $request->device_id) {
            if ($activeSession->device_id !== $request->device_id) {
                return response()->json([
                    'message' => 'Table is currently occupied by another device.',
                    'code'    => 'DEVICE_LOCKED',
                    'restaurant_name' => $table->restaurant->name, // Return minimal info for the error screen
                    'table_name' => $table->name
                ], 403);
            }
        }

        $sessionData = null;

        if ($activeSession) {
            // A. Aggregate items for the "Cart/Tab View" (Flat list of all items)
            $allSessionItems = $activeSession->orders
                ->flatMap(fn (Order $order) => $order->items)
                ->map(fn (OrderItem $item) => $this->transformOrderItem($item))
                ->values();

            // B. Map distinct orders for the "Live Tracker View"
            // Ensure ID and Status are explicitly from the ORDER, not the Session.
            $ordersList = $activeSession->orders->map(function (Order $order) {
                return [
                    'id'            => $order->id,               // Order ID
                    'status'        => $order->status->value,    // Order Status Enum Value
                    'total'         => (float) $order->total,
                    'items'         => $order->items->map(fn ($item) => $this->transformOrderItem($item)),
                    'timestamp'     => $order->created_at->timestamp * 1000, // JS format
                    'estimatedTime' => '15-20 mins', // Placeholder: Could be calculated dynamically
                ];
            })->values();

            $sessionData = [
                'session_id' => $activeSession->id,
                'status'     => $activeSession->status, // Session status (e.g. 'active', 'closing')
                'total_due'  => $activeSession->totalDue(),
                'items'      => $allSessionItems,
                'orders'     => $ordersList,
            ];
        }

        // 3. Load Menu (Categories & Products)
        // Optimized to only load what's needed for the menu display.
        $categories = Category::where('restaurant_id', $table->restaurant_id)
            ->orderBy('position')
            ->with(['products' => fn ($query) => $query->where('is_available', true)])
            ->get();

        $mappedCategories = $categories->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'slug' => Str::slug($c->name),
        ]);

        $mappedProducts = $categories->flatMap(function ($cat) {
            return $cat->products->map(fn ($prod) => [
                'id'           => $prod->id,
                'category_id'  => $cat->id,
                'name'         => $prod->name,
                'description'  => $prod->description,
                'price'        => (float) $prod->price,
                'image'        => $prod->image_path ? Storage::disk('public')->url($prod->image_path) : null,
                'is_available' => (bool) $prod->is_available,
                'is_popular'   => false, // @todo: Hook up to analytics
            ]);
        })->values();

        return response()->json([
            'session' => [
                'id'                => $table->id,
                'table_name'        => $table->name,
                'restaurant_name'   => $table->restaurant->name,
                'currency'          => $table->restaurant->currency ?? 'EUR',
                'active_session_id' => $activeSession?->id ?? null,
                'session_status'    => $activeSession?->status ?? 'closed',
            ],
            'menu' => [
                'categories' => $mappedCategories,
                'products'   => $mappedProducts
            ],
            'active_session' => $sessionData, // This contains the Order list
        ]);
    }

    /**
     * POST /api/v1/portal/{code}/order
     * * Creates or Updates an order for the table.
     * Uses a Transaction to ensure inventory, table status, and order consistency.
     */
     public function store(Request $request, string $code): JsonResponse
    {
        // 1. Validate Table Code
        $table = Table::where('code', $code)->first();

        if (! $table) {
            return response()->json(['message' => 'Invalid table code'], 404);
        }

        $request->validate([
            'items'            => 'required|array|min:1',
            'items.*.product.id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'session_id'       => 'nullable|exists:table_sessions,id',
            'device_id'        => 'required|string|max:255', // Changed to required for safety
        ]);

        // ---------------------------------------------------------
        // 1. Resolve & Lock Strategy
        // ---------------------------------------------------------

        // Find ANY active session for this table (regardless of device)
        $existingSession = TableSession::where('table_id', $table->id)
            ->where('status', '!=', 'closed')
            ->latest() // In case there are multiple (legacy data), grab the newest
            ->first();

        $session = null;

        if ($existingSession) {
            // BLOCKING LOGIC: If a session exists, does it belong to this device?
            if ($existingSession->device_id !== $request->device_id) {
                return response()->json([
                    'message' => 'Table is currently occupied by another device.',
                    'code' => 'DEVICE_LOCKED'
                ], 403);
            }

            // It matches! We can safely use this session.
            $session = $existingSession;
        }

        // ---------------------------------------------------------
        // 2. Create Session if none exists
        // ---------------------------------------------------------
        if (! $session) {
            // Optional: If user provided a session_id but we didn't find it active above,
            // it likely means that specific session is closed.
            if ($request->session_id) {
                 return response()->json(['message' => 'Session is closed or invalid.'], 403);
            }

            $session = TableSession::create([
                'table_id'      => $table->id,
                'restaurant_id' => $table->restaurant_id,
                'device_id'     => $request->device_id, // <--- Locks the table to this ID
                'session_code'  => Str::random(8),
                'started_by'    => 'client',
                'status'        => 'active',
                'opened_at'      => now(),
            ]);
        }

        // ---------------------------------------------------------
        // 3. Resolve Target Order
        // ---------------------------------------------------------

        // Update last activity timestamp
        $session->update(['last_activity_at' => now()]);

        /** @var Order|null $lastPendingOrder */
        $lastPendingOrder = $session->orders()
            ->where('status', OrderStatus::PENDING)
            ->latest()
            ->first();


        $orderToProcess = $lastPendingOrder ?? new Order();

        return $this->processOrderTransaction($orderToProcess, $request->items, $table, $session, $orderToProcess->exists);
    }

    /**
     * POST /api/v1/portal/{code}/session/{sessionId}/close
     * * Closes the table session (Checkout).
     */
    public function closeSession(string $code, string $sessionId): JsonResponse
    {
        $table   = Table::where('code', $code)->firstOrFail();
        $session = TableSession::where('id', $sessionId)
            ->where('table_id', $table->id)
            ->firstOrFail();

        // 1. Validation: Check for Unpaid Orders
        // We cannot close if there is money owed.
        $unpaidOrders = $session->orders()
            ->where('payment_status', '!=', 'paid')
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($unpaidOrders) {
            return response()->json([
                'message' => 'Cannot close session. There are unpaid orders.'
            ], 422);
        }

        // 2. Validation: Check for Active Pipeline Orders
        // (Optional: You might want to allow closing "served" items, but definitely not "pending" or "cooking")
        $activePipeline = $session->orders()
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->exists();

        if ($activePipeline) {
             return response()->json([
                'message' => 'Cannot close session. Some orders are still being prepared.'
            ], 422);
        }

        DB::transaction(function () use ($session, $table) {
            $session->update([
                'status'    => 'closed',
                'closed_at' => now()
            ]);

            $table->update(['status' => 'needs_cleaning']);
        });

        return response()->json(['message' => 'Session closed successfully.']);
    }

    /* -------------------------------------------------------------------------- */
    /* Private Logic                                                              */
    /* -------------------------------------------------------------------------- */

    /**
     * Atomic logic to Create or Update an Order + Items.
     */
    private function processOrderTransaction(Order $order, array $incomingItemsData, Table $table, TableSession $session, bool $isUpdate): JsonResponse
    {
        try {
            $incomingCollection = collect($incomingItemsData);

            $order = DB::transaction(function () use ($order, $session, $table, $incomingCollection, $isUpdate) {
                // A. Initialize Order Header
                if (! $isUpdate) {
                    $order->fill([
                        'table_session_id' => $session->id,
                        'restaurant_id'    => $table->restaurant_id,
                        'table_id'         => $table->id,
                        'status'           => OrderStatus::PENDING,
                        'payment_status'   => PaymentStatus::UNPAID,
                        'source'           => 'portal',
                        'total'            => 0,
                        'opened_at'        => now(),
                    ])->save();
                }



                // Initiate status history
                $order->recordStatusChange(OrderStatus::PENDING, Auth::user()->id);


                $existingItems = $isUpdate ? $order->items->keyBy('id') : collect();
                $runningTotal  = 0;

                // B. Sync Items (Upsert Logic)
                foreach ($incomingCollection as $itemData) {
                    $prodId      = $itemData['product']['id'];
                    $qty         = $itemData['quantity'];
                    $price       = $itemData['product']['price'];
                    $notes       = $itemData['notes'] ?? null;
                    $orderItemId = $itemData['order_item_id'] ?? null;

                    if ($orderItemId) {
                        // UPDATE existing item
                        $existingItem = $existingItems->get($orderItemId);

                        if ($existingItem) {
                            if ($existingItem->status !== OrderItemStatus::PENDING && $qty < $existingItem->quantity) {
                                throw new \RuntimeException("Cannot decrease quantity of items sent to kitchen.");
                            }

                            $existingItem->update([
                                'quantity'    => $qty,
                                'notes'       => $notes,
                                'total_price' => $price * $qty
                            ]);
                            $runningTotal += ($price * $qty);
                        }
                    } else {
                        // CREATE new item
                        OrderItem::create([
                            'order_id'    => $order->id,
                            'product_id'  => $prodId,
                            'quantity'    => $qty,
                            'unit_price'  => $price,
                            'total_price' => $price * $qty,
                            'notes'       => $notes,
                            'status'      => OrderItemStatus::PENDING,
                        ]);
                        $runningTotal += ($price * $qty);
                    }
                }

                // C. Handle Deletions (If item was removed from cart UI)
                if ($isUpdate) {
                    // Get IDs present in the incoming request
                    $incomingIds = $incomingCollection->pluck('order_item_id')->filter();

                    // Find items in DB that are NOT in request
                    $itemsToDelete = $existingItems->whereNotIn('id', $incomingIds);

                    foreach ($itemsToDelete as $item) {
                        if ($item->status !== OrderItemStatus::PENDING) {
                             throw new \RuntimeException("Cannot remove items already cooking.");
                        }
                        $item->delete();
                    }
                }

                // D. Update Order Totals
                $order->update([
                    'total' => $runningTotal,
                    ]);
                $table->update(['status' => 'occupied']);

                return $order;
            });

            // E. Fire Events (Only if new order created, to avoid KDS spam on small edits)
            if (! $isUpdate) {
                $order->loadMissing(['items.product', 'table', 'waiter']);
                event(new OrderCreated($order));
            }

            // return response()->json(['success' => true, 'order_id' => $order->id]);

            return response()->json([
                'session_id'    => $session->id, // NEW: Return the session ID
                'order_id'      => $order->id,
                'status'        => $order->status,
                'estimatedTime' => '15-20 mins',
                'timestamp'     => now()->timestamp * 1000,
            ]);

        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Order processing error.',
                'error'   => config('app.debug') ? $e->getMessage() : null
            ], 422);
        }
    }

    /**
     * Map OrderItem model to frontend DTO.
     */
    private function transformOrderItem(OrderItem $item): array
    {
        return [
            'order_id'      => $item->order_id,
            'order_status'  => $item->order->status->value,
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
            'quantity'      => $item->quantity,
            'notes'         => $item->notes,
            'status'        => $item->status instanceof OrderItemStatus ? $item->status->value : $item->status, // Enum safe
            'tempId'        => 'existing-' . $item->id,
        ];
    }
}
