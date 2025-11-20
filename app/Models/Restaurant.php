<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Restaurant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'currency',
        'timezone',
        'tax_rate',
        'service_charge_rate',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'tax_rate' => 'float',
        'service_charge_rate' => 'float',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(FloorPlan::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
