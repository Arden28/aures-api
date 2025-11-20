<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'order_id'    => $this->order_id,
            'product'     => $this->when($this->product, function () {
                return [
                    'id'    => $this->product->id,
                    'name'  => $this->product->name,
                    'price' => $this->product->price,
                ];
            }),
            'quantity'    => $this->quantity,
            'unit_price'  => $this->unit_price,
            'total_price' => $this->total_price,
            'status'      => $this->status->value,
            'notes'       => $this->notes,
        ];
    }
}
