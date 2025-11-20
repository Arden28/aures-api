<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TableResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'code'         => $this->code,
            'capacity'     => $this->capacity,
            'status'       => $this->status->value,
            'qr_token'     => $this->qr_token,
            'restaurant'     => $this->when($this->restaurant, function () {
                return [
                    'id'   => $this->restaurant->id,
                    'name' => $this->restaurant->name,

                ];
            }),
            'floor_plan'   => $this->when($this->floorPlan, function () {
                return [
                    'id'   => $this->floorPlan->id,
                    'name' => $this->floorPlan->name,
                ];
            }),
        ];
    }
}
