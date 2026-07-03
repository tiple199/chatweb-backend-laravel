<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewMessageNotification implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $targetUserId;
    public $conversationId;
    public $sender;
    public $chatName;
    public $content;
    public $messageType;

    /**
     * Create a new event instance.
     */
    public function __construct($targetUserId, $conversationId, $sender, $chatName, $content, $messageType)
    {
        $this->targetUserId = $targetUserId;
        $this->conversationId = $conversationId;
        $this->sender = $sender;
        $this->chatName = $chatName;
        $this->content = $content;
        $this->messageType = $messageType;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->targetUserId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'new_message_notification';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversationId' => $this->conversationId,
            'sender' => $this->sender,
            'chatName' => $this->chatName,
            'content' => $this->content,
            'messageType' => $this->messageType,
        ];
    }
}
