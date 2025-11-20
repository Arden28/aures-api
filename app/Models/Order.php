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
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
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
}
