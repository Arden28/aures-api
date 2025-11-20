<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FloorPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'restaurant'     => $this->when($this->restaurant, function () {
                return [
                    'id'   => $this->restaurant->id,
                    'name' => $this->restaurant->name,

                ];
            }),
            'name'       => $this->name,
            'status'     => $this->status->value,
            'tables'     => TableResource::collection($this->whenLoaded('tables')),
            'created_at' => $this->created_at,
        ];
    }
}
