<?php

namespace App\Events\Order;

use App\Models\OrderItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderItemStatusUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public OrderItem $item,
        public string $oldStatus,
        public string $newStatus,
    ) {}

    public function broadcastOn(): array
    {
        $order = $this->item->order;

        $channels = [
            new PrivateChannel('restaurant.' . $order->restaurant_id . '.kitchen'),
            new PrivateChannel('restaurant.' . $order->restaurant_id . '.waiters'),
            new PrivateChannel('order.' . $order->id),
        ];

        if ($order->table_id) {
            $channels[] = new PrivateChannel('table.' . $order->table_id);
        }

        return $channels;
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
