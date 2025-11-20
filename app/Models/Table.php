<?php

namespace App\Models;

use App\Enums\TableStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Table extends Model
{
    protected $fillable = [
        'restaurant_id',
        'floor_plan_id',
        'name',
        'code',
        'capacity',
        'qr_token',
        'status',
    ];

    protected $casts = [
        'status' => TableStatus::class,
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function floorPlan(): BelongsTo
    {
        return $this->belongsTo(FloorPlan::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
