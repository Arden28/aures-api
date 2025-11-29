<?php

namespace App\Events\Order;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public string $oldStatus,
        public string $newStatus,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('restaurant.' . $this->order->restaurant_id . '.kitchen'),
            new PrivateChannel('restaurant.' . $this->order->restaurant_id . '.waiters'),
            new PrivateChannel('restaurant.' . $this->order->restaurant_id . '.cashier'),

            // Public for Customer
            new Channel('order.' . $this->order->id),
        ];
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
