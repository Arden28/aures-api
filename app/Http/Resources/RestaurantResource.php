<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'name'                   => $this->name,
            'slug'                   => $this->slug,
            'logo_path'              => $this->logo_path,
            'currency'               => $this->currency,
            'timezone'               => $this->timezone,
            'tax_rate'               => $this->tax_rate,
            'service_charge_rate'    => $this->service_charge_rate,
            'settings'               => $this->settings,
        ];
    }
}
