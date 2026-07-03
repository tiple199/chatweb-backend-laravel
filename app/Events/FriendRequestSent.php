<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FriendRequestSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $senderId;
    public $senderName;
    public $recipientId;

    /**
     * Create a new event instance.
     */
    public function __construct($senderId, $senderName, $recipientId)
    {
        $this->senderId = $senderId;
        $this->senderName = $senderName;
        $this->recipientId = $recipientId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->recipientId),
        ];
    }

    public function broadcastAs()
    {
        return 'friend-request';
    }
}
