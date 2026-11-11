<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    protected $table = 'ai_conversations';

    protected $fillable = ['user_id', 'naslov'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function poruke()
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }
}
