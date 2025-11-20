<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
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
            'restaurant'     => $this->when($this->restaurant, function () {
                return [
                    'id'   => $this->restaurant->id,
                    'name' => $this->restaurant->name,

                ];
            }),
            'order_id'     => $this->order_id,
            'amount'       => $this->amount,
            'method'       => $this->method->value,
            'status'       => $this->status->value,
            'reference'    => $this->reference,
            'paid_at'      => $this->paid_at?->toDateTimeString(),
            'cashier'      => $this->when($this->cashier, function () {
                return [
                    'id'   => $this->cashier->id,
                    'name' => $this->cashier->name,
                ];
            }),
        ];
    }
}
