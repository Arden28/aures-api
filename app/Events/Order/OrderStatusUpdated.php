<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order,
        public string $oldStatus,
        public string $newStatus,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('restaurant.' . $this->order->restaurant_id . '.kitchen'),
            new PrivateChannel('restaurant.' . $this->order->restaurant_id . '.waiters'),
            new PrivateChannel('restaurant.' . $this->order->restaurant_id . '.cashier'),
            new PrivateChannel('order.' . $this->order->id),
        ];

        if ($this->order->table_id) {
            $channels[] = new PrivateChannel('table.' . $this->order->table_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id'    => $this->order->id,
            'table_id'    => $this->order->table_id,
            'old_status'  => $this->oldStatus,
            'new_status'  => $this->newStatus,
        ];
    }
}
