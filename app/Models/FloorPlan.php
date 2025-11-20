<?php

namespace App\Models;

use App\Enums\FloorPlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FloorPlan extends Model
{
    protected $fillable = [
        'restaurant_id',
        'name',
        'status',
    ];

    protected $casts = [
        'status' => FloorPlanStatus::class,
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }
}
