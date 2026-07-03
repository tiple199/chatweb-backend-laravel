<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'conversation_id',
        'content',
        'message_type',
        'file_url',
        'file_name',
        'file_size',
        'mime_type',
        'is_deleted_by_sender',
        'is_deleted_for_all',
        'deleted_at',
        'reply_to_message_id',
    ];

    protected $casts = [
        'is_deleted_by_sender' => 'boolean',
        'is_deleted_for_all' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function replyToMessage()
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }

    public function readBy()
    {
        return $this->belongsToMany(User::class, 'message_read_by')->withTimestamps();
    }
}
