<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VideoStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int    $videoId,
        public int    $userId,
        public string $status,
        public string $message,
        public array  $extra = []
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'video.status';
    }

    public function broadcastWith(): array
    {
        return [
            'video_id' => $this->videoId,
            'status'   => $this->status,
            'message'  => $this->message,
            'extra'    => $this->extra,
        ];
    }
}
