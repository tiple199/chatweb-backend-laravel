<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ClearedHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'cleared_at',
    ];

    protected $casts = [
        'cleared_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
