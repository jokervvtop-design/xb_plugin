<?php

namespace Plugin\TgAiAssistant\Services;

use Illuminate\Support\Facades\Schema;
use Plugin\TgAiAssistant\Models\AiGroupMemory;
use Plugin\TgAiAssistant\Models\AiKnowledge;

class GroupMemoryCollector
{
    public function __construct(protected array $config)
    {
    }

    public function collect(object $msg, ?int $telegramUserId): void
    {
        if (empty($this->config['enable_group_memory'])) {
            return;
        }

        if ($msg->is_private ?? false) {
            return;
        }

        if (($msg->message_type ?? '') !== 'message') {
            return;
        }

        if (!$telegramUserId) {
            return;
        }

        $text = trim((string) ($msg->text ?? ''));
        if (!$this->isCollectableText($text)) {
            return;
        }

        if (!$this->isAllowedChat((int) $msg->chat_id)) {
            return;
        }

        $text = mb_substr($text, 0, 2000);

        if (!empty($this->config['group_memory_auto_import'])) {
            $this->importToKnowledge($text);
            return;
        }

        if (!Schema::hasTable('v2_tg_ai_group_memory')) {
            return;
        }

        AiGroupMemory::create([
            'chat_id' => (int) $msg->chat_id,
            'telegram_user_id' => $telegramUserId,
            'message_text' => $text,
            'status' => AiGroupMemory::STATUS_PENDING,
            'created_at' => time(),
        ]);
    }

    protected function importToKnowledge(string $text): void
    {
        if (!Schema::hasTable('v2_tg_ai_knowledge')) {
            return;
        }

        $exists = AiKnowledge::query()
            ->where('source', 'group_import')
            ->where('content', $text)
            ->exists();

        if ($exists) {
            return;
        }

        $title = mb_strlen($text) > 50 ? mb_substr($text, 0, 50) . '...' : $text;

        AiKnowledge::create([
            'title' => $title,
            'content' => $text,
            'source' => 'group_import',
            'language' => 'zh-CN',
            'show' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    protected function isCollectableText(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        if (str_starts_with($text, '/')) {
            return false;
        }

        $minLength = max(1, (int) ($this->config['group_memory_min_length'] ?? 10));
        if (mb_strlen($text) < $minLength) {
            return false;
        }

        return true;
    }

    protected function isAllowedChat(int $chatId): bool
    {
        $raw = trim((string) ($this->config['group_memory_chat_ids'] ?? ''));
        if ($raw === '' || $raw === '[]') {
            return true;
        }

        $chatIds = json_decode($raw, true);
        if (!is_array($chatIds) || empty($chatIds)) {
            return true;
        }

        return in_array($chatId, array_map('intval', $chatIds), true);
    }
}
