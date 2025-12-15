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

class TransactionController extends Controller
{
    /**
     * List transactions for the authenticated restaurant.
     */
    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant_id;

        $transactions = Transaction::with(['order', 'cashier'])
            ->whereHas('order', function ($q) use ($restaurantId) {
                $q->where('restaurant_id', $restaurantId);
            })
            ->latest()
            ->paginate(20);

        return response()->json(TransactionResource::collection($transactions));
    }

    /**
     * Create a transaction and update the order payment fields.
     */
public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Custom validation to allow either order_id OR table_session_id
        $request->validate([
            'amount'           => 'required|numeric|min:0.01',
            'method'           => 'required|string',
            'order_id'         => 'nullable|exists:orders,id',
            'table_session_id' => 'nullable|exists:table_sessions,id',
        ]);

        return DB::transaction(function () use ($request, $user) {

            // 1. Resolve the Scope (Single Order vs Global Session)
            $ordersToSettle = collect();
            $referenceOrder = null; // We need one order to link the transaction to (for DB constraints)

            if ($request->filled('table_session_id')) {
                // Fetch all UNPAID orders in this session
                $session = TableSession::with('orders')
                    ->where('id', $request->table_session_id)
                    ->firstOrFail();

                $ordersToSettle = $session->orders()
                    ->where('payment_status', '!=', PaymentStatus::PAID)
                    ->where('status', '!=', OrderStatus::CANCELLED)
                    ->get();

                // Use the latest order as the primary reference for the transaction record
                $referenceOrder = $ordersToSettle->last();
            } elseif ($request->filled('order_id')) {
                $referenceOrder = Order::findOrFail($request->order_id);
                $ordersToSettle->push($referenceOrder);
            } else {
                abort(422, 'Either order_id or table_session_id is required.');
            }

            if ($ordersToSettle->isEmpty()) {
                abort(422, 'No unpaid orders found to settle.');
            }

            // 2. Create the Financial Transaction
            // We link it to the reference order, but logically it covers the session
            $transaction = Transaction::create([
                'order_id'      => $referenceOrder->id,
                'processed_by'  => $user->id,
                'amount'        => $request->amount,
                'method'        => $request->input('method'),
                'status'        => PaymentStatus::PAID,
                'reference'     => $request->reference ?? null,
                'paid_at'       => now(),
                'table_session_id' => $request->table_session_id
                // If you have a 'table_session_id' column in transactions table, add it here:
            ]);

            // 3. Distribute Payment / Close Orders
            // In a simple full-payment scenario, we mark everything as PAID.
            // (Complex partial logic omitted for brevity, assuming full payment for now)
            foreach ($ordersToSettle as $order) {
                $order->update([
                    'payment_status' => PaymentStatus::PAID,
                    'paid_amount'    => $order->total, // Assume full coverage
                    // If the order was just "Served", strictly speaking, paying for it often completes it
                    'status'         => $order->status === OrderStatus::SERVED ? OrderStatus::COMPLETED : $order->status
                ]);
            }

            // 4. If this was a Session payment, Close the Session
            if ($request->filled('table_session_id')) {
                 $session->update([
                    'status' => 'closed',
                    'closed_at' => now()
                 ]);

                 // Free up the table
                 if($session->table) {
                    $session->table()->update(['status' => 'needs_cleaning']); // or 'free'
                 }
            }

            return response()->json([
                'message'     => 'Payment recorded and session settled.',
                'transaction' => new TransactionResource($transaction),
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
