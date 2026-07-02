<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParticipantsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $conversationId;
    public string $userId;
    public string $action; // 'add' | 'remove' | 'leave'
    public ?string $role;

    public function __construct(string $conversationId, string $userId, string $action, ?string $role = null)
    {
        $this->conversationId = $conversationId;
        $this->userId         = $userId;
        $this->action         = $action;
        $this->role           = $role;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.' . $this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'participants.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'ConversationId' => $this->conversationId,
            'UserId'         => $this->userId,
            'Action'         => $this->action,
            'Role'           => $this->role,
        ];
    }
}
