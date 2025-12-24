<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TableSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'table_id',
        'restaurant_id',
        'assigned_waiter_id',
        'session_code',
        'started_by',
        'status',
        'opened_at',
        'closed_at',
        'device_id',
        'last_activity_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'status' => 'string',
        'device_id' => 'string',
        'last_activity_at' => 'datetime',
    ];

    // Relationships
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function assignedWaiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_waiter_id');
    }

    // A session can have multiple orders
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // Helper to get the total amount due for this session (sum of all non-cancelled/non-completed orders)
    public function totalDue(): float
    {
        return (float) $this->orders()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->sum('total');
    }
}
