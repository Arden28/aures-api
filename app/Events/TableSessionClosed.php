<?php

namespace App\Events;

use App\Models\TableSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // Use Now for instant updates
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TableSessionClosed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public TableSession $session
    ) {}

    public function broadcastOn(): array
    {
        // We broadcast on a specific channel for this session ID
        return [
            new Channel('table.session.' . $this->session->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'table.session.closed';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->id,
            'closed_at'  => $this->session->closed_at,
        ];
    }
}
