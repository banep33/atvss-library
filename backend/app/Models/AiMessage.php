<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMessage extends Model
{
    protected $table = 'ai_messages';

    protected $fillable = ['conversation_id', 'role', 'content'];

    // Ažurira ai_conversations.updated_at pri svakoj novoj poruci —
    // koristi se za sortiranje liste razgovora po najnovijoj aktivnosti.
    protected $touches = ['konverzacija'];

    public function konverzacija()
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
