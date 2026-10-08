<?php

namespace Plugin\TgAiAssistant\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversation extends Model
{
    protected $table = 'v2_tg_ai_conversations';
    protected $dateFormat = 'U';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
    ];
}
