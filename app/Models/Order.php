<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'restaurant_id',
        'table_session_id',
        'table_id',
        'client_id',
        'waiter_id',
        'status',
        'source',
        'subtotal',
        'tax_amount',
        'service_charge',
        'discount_amount',
        'total',
        'paid_amount',
        'payment_status',
        'opened_at',
        'closed_at',
        'statusHistory',
    ];

    protected $casts = [
        'status'         => OrderStatus::class,
        'payment_status' => PaymentStatus::class,
        'subtotal'       => 'float',
        'tax_amount'     => 'float',
        'service_charge' => 'float',
        'discount_amount'=> 'float',
        'total'          => 'float',
        'paid_amount'    => 'float',
        'opened_at'      => 'datetime',
        'closed_at'      => 'datetime',
        'statusHistory'  => 'array',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Helper to append a new status to history.
     */
    public function recordStatusChange(OrderStatus $newStatus, ?int $userId = null): void
    {
        // 1. Get existing history or initialize empty array
        $history = $this->statusHistory ?? [];

        // 2. Append new entry
        $history[] = [
            'status'  => $newStatus->value,
            'at'      => now()->format("Y-m-d H:i:s"), // Standard ISO format
            'user_id' => $userId, // Useful to know WHO changed it
        ];

        // 3. Re-assign to trigger Eloquent's dirty checking
        $this->statusHistory = $history;
    }

}
