<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    /**
     * List transactions.
     * Logic:
     * 1. Scope to Restaurant.
     * 2. Scope to TODAY (Register Shift).
     * 3. Scope to USER (If not Manager/Owner).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $restaurantId = $user->restaurant_id;

        $query = Transaction::with(['order.table', 'cashier'])
            ->whereHas('order', function ($q) use ($restaurantId) {
                $q->where('restaurant_id', $restaurantId);
            })
            ->latest();

        // 1. Time Scope: Transactions from Today Only
        // Matches OrderController logic
        $query->whereBetween('created_at', [
            now()->startOfDay(),
            now()->endOfDay()
        ]);

        // 2. Role Scope: Operational users see ONLY their own drawer
        // Owners/Managers can see the full list.
        if ($user->role !== 'owner' && $user->role !== 'manager') {
            $query->where('processed_by', $user->id);
        }

        // Optional: Allow frontend to force "my_transactions" view even for managers
        if ($request->boolean('my_transactions')) {
            $query->where('processed_by', $user->id);
        }

        // Calculate total for this filtered view (Solves pagination sum issue)
        $totalCollected = $query->sum('amount');

        $perPage = (int) $request->query('per_page', 50);
        $transactions = $query->paginate($perPage);

        // Return data with a Meta field for the total sum
        return response()->json(
            TransactionResource::collection($transactions)->additional([
                'meta' => [
                    'total_collected' => $totalCollected
                ]
            ])
        );
    }

    /**
     * Create a transaction and update the order payment fields.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'amount'           => 'required|numeric|min:0.01',
            'method'           => 'nullable|string',
            // Support array of IDs for grouped takeout payments
            'order_ids'        => 'nullable|array',
            'order_ids.*'      => 'exists:orders,id',
            'table_session_id' => 'nullable|exists:table_sessions,id',
        ]);

        return DB::transaction(function () use ($request, $user) {

            // 1. Resolve Scope & Lock Rows (Prevent double payment)
            $ordersToSettle = collect();
            $session = null;

            if ($request->filled('table_session_id')) {
                // SCENARIO A: Table Session (Pay everything at the table)
                $session = TableSession::where('id', $request->table_session_id)
                    ->lockForUpdate() // <--- Critical for concurrency
                    ->firstOrFail();

                if ($session->status === 'closed') {
                    throw ValidationException::withMessages(['table_session_id' => 'This session is already closed.']);
                }

                $ordersToSettle = $session->orders()
                    ->where('payment_status', '!=', 'paid')
                    ->where('status', '!=', 'cancelled')
                    ->lockForUpdate()
                    ->get();
            }
            elseif ($request->filled('order_ids')) {
                // SCENARIO B: Grouped Takeout (Specific list of orders)
                $ordersToSettle = Order::whereIn('id', $request->order_ids)
                    ->where('payment_status', '!=', 'paid')
                    ->lockForUpdate()
                    ->get();
            }

            if ($ordersToSettle->isEmpty()) {
                abort(422, 'No unpaid orders found to settle.');
            }

            // 2. Validate Financials
            // ensure the payment covers the total.
            // (Optional: You can allow partials here, but for "Closing" we expect full)
            $totalDue = $ordersToSettle->sum('total');

            // Allow a small float margin of error or exact match.
            // If providing change, $request->amount might be higher.
            if ($request->amount < $totalDue) {
                // Optional: throw error or mark as 'partial'
                // abort(422, "Insufficient amount. Total due is {$totalDue}");
            }

            // 3. Create Transaction Record
            // We link to the session if it exists, otherwise the first order
            $referenceOrder = $ordersToSettle->first();

            $transaction = Transaction::create([
                'order_id'         => $referenceOrder->id, // Primary link
                'table_session_id' => $session ? $session->id : null,
                'processed_by'     => $user->id,
                'amount'           => $request->amount,
                'method'           => $request->input('method') ?? 'cash',
                'status'           => 'paid',
                'reference'        => $request->reference ?? null, // e.g., M-Pesa Code
                'paid_at'          => now(),
            ]);

            // 4. Update Order Statuses
            foreach ($ordersToSettle as $order) {
                $updateData = [
                    'payment_status' => 'paid',
                    'paid_amount'    => $order->total, // Allocate full amount to order
                    'updated_at'     => now(),
                ];

                // Auto-complete workflow: If it was served/ready, it is now done.
                if (in_array($order->status, ['served', 'ready'])) {
                    $updateData['status'] = 'completed';
                    $updateData['closed_at'] = now();
                }

                $order->update($updateData);
                $order->update([
                    'status' => 'completed'
                ]);

                // status change record
                $order->recordStatusChange(OrderStatus::COMPLETED, $user->id);
            }

            // 5. Close Session & Free Table (If applicable)
            if ($session) {
                $session->update([
                    'status'    => 'closed',
                    'closed_at' => now(),
                ]);

                if ($session->table) {
                    // Mark table as dirty so waiters know to clean it before seating new people
                    $session->table()->update(['status' => 'free']);
                }
            }

            return response()->json([
                'message'     => 'Payment processed successfully.',
                'transaction' => $transaction,
                'orders_updated' => $ordersToSettle->count()
            ], 201);
        });
    }

    /**
     * Show a single transaction (scoped).
     */
    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeRestaurant($request, $transaction);

        $transaction->loadMissing('cashier', 'order');

        return response()->json(new TransactionResource($transaction));
    }

    protected function authorizeRestaurant(Request $request, Transaction $transaction): void
    {
        $restaurantId = $request->user()->restaurant_id;

        if ($transaction->order->restaurant_id !== $restaurantId) {
            abort(403, 'Forbidden for this restaurant.');
        }
    }
}
