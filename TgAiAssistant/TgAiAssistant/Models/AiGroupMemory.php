<?php

namespace Plugin\TgAiAssistant\Models;

use Illuminate\Database\Eloquent\Model;

class AiGroupMemory extends Model
{
    protected $table = 'v2_tg_ai_group_memory';
    protected $dateFormat = 'U';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_IGNORED = 'ignored';
}
