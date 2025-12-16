<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'restaurant'     => $this->when($this->restaurant, function () {
                return [
                    'id'   => $this->restaurant->id,
                    'name' => $this->restaurant->name,

                ];
            }),
            'table'          => $this->when($this->table, function () {
                return [
                    'id'   => $this->table->id,
                    'name' => $this->table->name,

                ];
            }),
            'table_session_id' => $this->table_session_id,
            'table_session' => $this->when($this->session, function () {
                return [
                    'id'        => $this->session->id,
                    'opened_at' => $this->session->opened_at?->toDateTimeString(),
                    'closed_at' => $this->session->closed_at?->toDateTimeString(),
                    'status'    => $this->session->status,
                ];
            }),
            'client'         => $this->when($this->client, function () {
                return [
                    'id'   => $this->client->id,
                    'name' => $this->client->name,

                ];
            }),
            'waiter'         => $this->when($this->waiter, function () {
                return [
                    'id'   => $this->waiter->id,
                    'name' => $this->waiter->name,

                ];
            }),
            'status'         => $this->status->value,
            'source'         => $this->source,
            'subtotal'       => $this->subtotal,
            'tax_amount'     => $this->tax_amount,
            'service_charge' => $this->service_charge,
            'discount_amount'=> $this->discount_amount,
            'total'          => $this->total,
            'paid_amount'    => $this->paid_amount,
            'payment_status' => $this->payment_status->value,
            'opened_at'      => $this->opened_at?->toDateTimeString(),
            'closed_at'      => $this->closed_at?->toDateTimeString(),
            'items'          => OrderItemResource::collection($this->whenLoaded('items')),
            'transactions'   => TransactionResource::collection($this->whenLoaded('transactions')),
        ];
    }
}
