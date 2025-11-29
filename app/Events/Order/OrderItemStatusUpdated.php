<?php

namespace App\Events\Order;

use App\Models\OrderItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderItemStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public OrderItem $item,
        public string $oldStatus,
        public string $newStatus,
    ) {}

    public function broadcastOn(): array
    {
        $order = $this->item->order;

        return [
            new PrivateChannel('restaurant.' . $order->restaurant_id . '.kitchen'),
            new PrivateChannel('restaurant.' . $order->restaurant_id . '.waiters'),

            // Public for Customer
            new Channel('order.' . $order->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.item.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id'   => $this->item->order_id,
            'item_id'    => $this->item->id,
            'product_id' => $this->item->product_id,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
        ];
    }
}
