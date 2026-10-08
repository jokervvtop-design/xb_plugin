<?php

namespace Plugin\TgAiAssistant\Jobs;

use App\Models\Plugin as PluginModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugin\TgAiAssistant\Plugin;
use Plugin\TgAiAssistant\Services\ModerationClassifier;
use Plugin\TgAiAssistant\Services\ModerationTelegramService;
use Plugin\TgAiAssistant\Services\ModerationNotifier;

class ModerateGroupMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(protected array $message)
    {
        $this->onQueue('send_telegram');
    }

    public function handle(): void
    {
        $record = PluginModel::where('code', 'tg_ai_assistant')->where('is_enabled', true)->first();
        if (!$record) {
            return;
        }
        $config = json_decode($record->config ?? '{}', true);
        if (!is_array($config)) {
            return;
        }
        $chatId = (int) $this->message['chat_id'];
        $messageId = (int) $this->message['message_id'];
        $userId = (int) $this->message['tg_ai_sender_id'];
        $messageKey = "tg_ai_moderation_{$chatId}_{$messageId}";
        $key = $messageKey . '_' . hash('sha256', (string) $this->message['text']);
        $lock = Cache::lock($key . '_lock', 300);
        if (!$lock->get()) {
            return;
        }
        $audit = null;
        try {
            if (Cache::has($key)) {
                return;
            }
            // At most one enforcement attempt per Telegram message, even on webhook retries.
            Cache::put($key, true, 172800);
            $plugin = new Plugin('tg_ai_assistant');
            $plugin->setConfig($config);
            if (empty($config['enable_moderation'])) {
                $plugin->processApprovedMessage($this->message);
                return;
            }
            if (!empty($this->message['tg_ai_is_edit']) && !($config['moderation_watch_edits'] ?? true)) {
                return;
            }
            $chatIds = json_decode((string) ($config['moderation_chat_ids'] ?? '[]'), true);
            if (!is_array($chatIds) || ($chatIds !== [] && !in_array($chatId, array_map('intval', $chatIds), true))) {
                $plugin->processApprovedMessage($this->message);
                return;
            }
            $threshold = max(0.5, min(1.0, (float) ($config['moderation_confidence'] ?? 0.9)));
            $audit = [
                'model' => ($config['moderation_model'] ?? '') ?: ($config['llm_model'] ?? 'gpt-4o-mini'),
                'threshold' => $threshold,
                'stage' => '审核前置检查',
                'result' => '前置检查失败，未调用 AI，未执行处罚',
            ];
            if (trim((string) ($config['llm_api_key'] ?? '')) === '') {
                throw new \RuntimeException('群聊审核未配置 API Key');
            }
            $telegram = new ModerationTelegramService();
            $member = $telegram->member($chatId, $userId);
            if (in_array($member->status ?? '', ['creator', 'administrator', 'left', 'kicked'], true)) {
                $audit = null;
                $plugin->processApprovedMessage($this->message);
                return;
            }
            $audit['stage'] = 'AI 审核';
            $audit['result'] = '审核异常，未执行处罚';
            $decision = (new ModerationClassifier())->classify((string) $this->message['text'], $config);
            $audit['decision'] = $decision;
            if (!$decision['violation'] || $decision['confidence'] < $threshold) {
                $audit['result'] = $decision['violation'] ? '置信度未达门槛，未处罚' : '正常，未处罚';
                $plugin->processApprovedMessage($this->message);
                return;
            }
            $minutes = max(1, min(525600, (int) ($config['moderation_mute_minutes'] ?? 60)));
            $audit['enforcement_attempted'] = true;
            $audit['minutes'] = $minutes;
            $audit['result'] = '违规，已尝试删除消息及禁言';
            $deleted = false;
            $muted = false;
            foreach (['delete', 'mute'] as $action) {
                try {
                    if ($action === 'delete') {
                        $telegram->deleteMessage($chatId, $messageId);
                        $deleted = true;
                        $audit['deleted'] = true;
                    } else {
                        $telegram->mute($chatId, $userId, $minutes * 60);
                        $muted = true;
                        $audit['muted'] = true;
                    }
                } catch (\Throwable $e) {
                    Log::warning('TgAiAssistant 审核处罚失败', [
                        'chat_id' => $chatId, 'message_id' => $messageId,
                        'action' => $action, 'error' => $e->getMessage(),
                    ]);
                }
            }
            $categories = ['porn' => '色情', 'gambling' => '赌博', 'drugs' => '毒品',
                'explosives' => '爆炸物', 'other' => '群规'];
            $name = trim((string) ($this->message['tg_ai_sender_name'] ?? '')) ?: (string) $userId;
            $name = preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name) ?? (string) $userId;
            $notice = '⚠️ ' . $name . '（ID：' . $userId . '）违规（' . $categories[$decision['category']] . '），'
                . ($muted ? "已被禁言 {$minutes} 分钟" : '禁言失败，请管理员处理')
                . ($deleted ? '，违规消息已删除。' : '，删除消息失败，请管理员处理。');
            Log::info('TgAiAssistant 群聊违规处理', [
                'chat_id' => $chatId, 'message_id' => $messageId, 'user_id' => $userId,
                'category' => $decision['category'], 'confidence' => $decision['confidence'],
                'deleted' => $deleted, 'muted' => $muted,
            ]);
            $telegram->sendMessage($chatId, $notice);
            $audit['group_notice_sent'] = true;
        } catch (\Throwable $e) {
            if ($audit !== null) {
                $audit['error'] = ($audit['stage'] ?? '') === '审核前置检查'
                    && $e instanceof \RuntimeException ? $e->getMessage() : '处理失败，具体原因请查看服务器日志';
            }
            // An unavailable API or malformed decision must never trigger punishment.
            Log::warning('TgAiAssistant 群聊审核处理失败', [
                'chat_id' => $chatId, 'message_id' => $messageId, 'error' => $e->getMessage(),
            ]);
        } finally {
            if ($audit !== null) {
                (new ModerationNotifier())->notify($config, $this->message, $audit);
            }
            $lock->release();
        }
    }
}
