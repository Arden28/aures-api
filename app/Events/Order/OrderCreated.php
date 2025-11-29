<?php

namespace App\Events\Order;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast; // Import this
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// 1. MUST implement ShouldBroadcast
class OrderCreated implements ShouldBroadcastNow
{
    // 2. Add InteractsWithSockets trait
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('restaurant.' . $this->order->restaurant_id . '.kitchen'),
            new PrivateChannel('restaurant.' . $this->order->restaurant_id . '.waiters'),
            new PrivateChannel('order.' . $this->order->id),
        ];

        if ($this->order->table_id) {
            $channels[] = new PrivateChannel('table.' . $this->order->table_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        $resource = new OrderResource(
            $this->order->loadMissing(['items.product', 'table', 'waiter'])
        );

        return [
            'order' => $resource->resolve(),
        ];
    }
}
