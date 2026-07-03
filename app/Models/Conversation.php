<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_name',
        'is_group_chat',
        'latest_message_id',
        'creator_id',
    ];

    protected $casts = [
        'is_group_chat' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'conversation_user')
            ->withPivot('is_admin')
            ->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->belongsTo(Message::class, 'latest_message_id');
    }

    public function polls()
    {
        return $this->hasMany(Poll::class);
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }
}
