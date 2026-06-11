<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappConversationMessage extends Model
{
    protected $fillable = [
        'whatsapp_session_id',
        'direction',
        'source',
        'body',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class, 'whatsapp_session_id');
    }
}
