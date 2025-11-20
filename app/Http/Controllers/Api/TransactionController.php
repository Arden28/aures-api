<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Order;
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
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $user       = $request->user();
        $restaurantId = $user->restaurant_id;
        $data       = $request->validated();

        $order = Order::where('id', $data['order_id'])
            ->where('restaurant_id', $restaurantId)
            ->firstOrFail();

        $transaction = DB::transaction(function () use ($data, $order, $user) {
            $transaction = Transaction::create([
                'order_id'    => $order->id,
                'processed_by'=> $user->id,
                'amount'      => $data['amount'],
                'method'      => $data['method'],
                'status'      => PaymentStatus::PAID,
                'reference'   => $data['reference'] ?? null,
                'paid_at'     => now(),
            ]);

            // Update order payment state
            $order->paid_amount = ($order->paid_amount ?? 0) + $transaction->amount;

            if ($order->paid_amount <= 0) {
                $order->payment_status = PaymentStatus::UNPAID;
            } elseif ($order->paid_amount + 0.0001 < $order->total) { // floating margin
                $order->payment_status = PaymentStatus::PARTIAL;
            } else {
                $order->payment_status = PaymentStatus::PAID;
            }

            $order->save();

            return $transaction;
        });

        return response()->json([
            'message'     => 'Payment recorded successfully.',
            'transaction' => new TransactionResource($transaction->load('cashier')),
        ], 201);
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
