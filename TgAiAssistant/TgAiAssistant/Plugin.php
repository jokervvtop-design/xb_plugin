<?php

namespace Plugin\TgAiAssistant;

use App\Models\Plugin as PluginModel;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugin\TgAiAssistant\Jobs\ProcessAiReplyJob;
use Plugin\TgAiAssistant\Jobs\ModerateGroupMessageJob;
use Plugin\TgAiAssistant\Services\GroupMemoryCollector;

class Plugin extends AbstractPlugin
{
    protected TelegramService $telegramService;

    public function boot(): void
    {
        if (!$this->isTelegramPluginEnabled()) {
            Log::warning('TgAiAssistant 已启用，但 Telegram Bot 集成插件未启用');
            return;
        }

        $this->telegramService = new TelegramService();
        $this->listen('telegram.message.before', [$this, 'moderateGroupMessage'], 1);
        $this->filter('telegram.message.handle', [$this, 'handleMessage'], 8);
        $this->filter('telegram.bot.commands', [$this, 'addBotCommands'], 8);
        $this->listen('telegram.message.after', [$this, 'collectGroupMemory'], 5);
        $this->handleEditedWebhook();
    }

    protected function isTelegramPluginEnabled(): bool
    {
        return PluginModel::where('code', 'telegram')->where('is_enabled', true)->exists();
    }

    public function addBotCommands(array $commands): array
    {
        if (!$this->getConfig('enable_ai', true)) {
            return $commands;
        }

        $commands[] = [
            'command' => '/ask',
            'description' => 'AI 智能问答',
        ];

        return $commands;
    }

    public function handleMessage(bool $handled, array $data): bool
    {
        if ($handled) {
            return $handled;
        }

        [$msg] = $data;

        if (!empty($msg->tg_ai_moderation_pending)) {
            return $handled;
        }

        if (!$this->getConfig('enable_ai', true)) {
            return $handled;
        }

        if ($msg->message_type !== 'message') {
            return $handled;
        }

        if (!$this->passesChatScope($msg)) {
            return $handled;
        }

        $question = $this->resolveQuestion($msg);
        if ($question === null) {
            return $handled;
        }

        if ($question === '') {
            $this->reply($msg, '请在 /ask 或 @机器人 后输入您的问题，例如：/ask 如何绑定账号？');
            return true;
        }

        $senderId = $msg->tg_ai_sender_id ?? $this->resolveSenderTelegramId();
        if ($this->getConfig('require_bind', false)
            && (!$senderId || !User::where('telegram_id', $senderId)->exists())) {
            $this->reply($msg, '❌ 请先绑定 XBoard 账号后再使用 AI 助手，发送 /bind 绑定');
            return true;
        }

        if (!$this->passesRateLimit($msg->chat_id)) {
            $this->reply($msg, '⏳ 请求过于频繁，请稍后再试');
            return true;
        }

        $apiKey = trim((string) $this->getConfig('llm_api_key', ''));
        if ($apiKey === '') {
            $this->reply($msg, 'AI 服务未配置 API Key，请联系管理员在插件设置中填写');
            return true;
        }

        $thinkingText = (string) $this->getConfig('thinking_text', '🤔 思考中，请稍候...');
        if ($thinkingText !== '') {
            $this->reply($msg, $thinkingText);
        }

        ProcessAiReplyJob::dispatch(
            (int) $msg->chat_id,
            $question,
            $msg->tg_ai_sender_id ?? $this->resolveSenderTelegramId(),
            isset($msg->message_id) ? (int) $msg->message_id : null
        );

        return true;
    }

    protected function resolveQuestion(object $msg): ?string
    {
        $command = $msg->command ?? '';

        if ($command === '/ask') {
            return trim(implode(' ', $msg->args ?? []));
        }

        if (str_starts_with($command, '/')) {
            return null;
        }

        if (!$msg->is_private) {
            return $this->resolveMentionQuestion((string) ($msg->text ?? ''));
        }

        if (!$this->getConfig('enable_free_text', false)) {
            return null;
        }

        return trim((string) ($msg->text ?? ''));
    }

    protected function resolveMentionQuestion(string $text): ?string
    {
        // Check for a mention before making a Telegram API request.
        if (!preg_match('/(?<![\p{L}\p{N}_@])@[a-zA-Z0-9_]+(?![a-zA-Z0-9_])/u', $text)) {
            return null;
        }

        try {
            $cacheKey = 'tg_ai_assistant_bot_username_' . hash(
                'sha256',
                (string) admin_setting('telegram_bot_token', '')
            );
            $username = Cache::remember($cacheKey, 3600, function (): string {
                $response = $this->telegramService->getMe();
                $username = $response->result->username ?? null;
                if (!is_string($username) || $username === '') {
                    throw new \RuntimeException('Telegram Bot 用户名为空');
                }
                return $username;
            });
        } catch (\Throwable $e) {
            Log::warning('TgAiAssistant 获取机器人用户名失败');
            return null;
        }

        $pattern = '/(?<![\p{L}\p{N}_@])@' . preg_quote($username, '/')
            . '(?![a-zA-Z0-9_])/iu';
        if (!preg_match($pattern, $text)) {
            return null;
        }

        return trim(preg_replace($pattern, '', $text) ?? $text);
    }

    protected function passesChatScope(object $msg): bool
    {
        if ($msg->is_private) {
            return (bool) $this->getConfig('enable_private', true);
        }

        return (bool) $this->getConfig('enable_group', false);
    }

    protected function resolveSenderTelegramId(): ?int
    {
        $message = request()->json('message');
        if (!is_array($message)) {
            return null;
        }

        if (!empty($message['from']['is_bot'])) {
            return null;
        }

        $fromId = $message['from']['id'] ?? null;
        if (!is_numeric($fromId) && !empty($message['chat']['type']) && $message['chat']['type'] === 'private') {
            $fromId = $message['chat']['id'] ?? null;
        }

        return is_numeric($fromId) ? (int) $fromId : null;
    }

    protected function resolveBoundUser(): ?User
    {
        $telegramUserId = $this->resolveSenderTelegramId();
        if (!$telegramUserId) {
            return null;
        }

        return User::where('telegram_id', $telegramUserId)->first();
    }

    protected function passesRateLimit(int $chatId): bool
    {
        $limit = max(1, (int) $this->getConfig('rate_limit_per_minute', 10));
        $cacheKey = 'tg_ai_assistant_rate_' . $chatId;

        $count = (int) Cache::get($cacheKey, 0);
        if ($count >= $limit) {
            return false;
        }

        Cache::put($cacheKey, $count + 1, now()->addMinute());

        return true;
    }

    public function collectGroupMemory(array $data): void
    {
        [$msg] = $data;

        if (!empty($msg->tg_ai_moderation_pending)) {
            return;
        }

        try {
            (new GroupMemoryCollector($this->config))->collect(
                $msg,
                $msg->tg_ai_sender_id ?? $this->resolveSenderTelegramId()
            );
        } catch (\Throwable $e) {
            Log::warning('TgAiAssistant 群聊记忆采集失败', [
                'chat_id' => $msg->chat_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function moderateGroupMessage(array $data): void
    {
        if (!$this->getConfig('enable_moderation', false)) {
            return;
        }
        [$msg] = $data;
        $raw = request()->json('message');
        $this->queueModerationMessage($msg, $raw);
    }

    protected function handleEditedWebhook(): void
    {
        // The core Telegram controller ignores edited_message, so receive it
        // here using the same webhook path and access-token authentication.
        $request = request();
        if (!$this->getConfig('moderation_watch_edits', true)
            || !$request->isMethod('POST') || !$request->is('api/v1/guest/telegram/webhook')) {
            return;
        }
        $raw = $request->json('edited_message');
        $token = (string) admin_setting('telegram_bot_token', '');
        $accessToken = $request->input('access_token');
        if (!is_array($raw) || $token === '' || !is_string($accessToken)
            || !hash_equals(md5($token), $accessToken)) {
            return;
        }
        $msg = (object) [
            'chat_id' => $raw['chat']['id'] ?? 0,
            'message_id' => $raw['message_id'] ?? 0,
            'message_type' => 'edited_message',
            'is_private' => ($raw['chat']['type'] ?? '') === 'private',
            'text' => (string) ($raw['text'] ?? ''),
            'command' => '',
            'args' => [],
            'tg_ai_is_edit' => true,
        ];
        $this->queueModerationMessage($msg, $raw);
    }

    protected function queueModerationMessage(object $msg, mixed $raw): void
    {
        if (!$this->getConfig('enable_moderation', false)) {
            return;
        }
        if (!is_array($raw) || ($raw['chat']['type'] ?? '') !== 'supergroup'
            || !empty($raw['from']['is_bot']) || !empty($raw['sender_chat'])
            || empty($raw['from']['id']) || trim((string) ($raw['text'] ?? '')) === '') {
            return;
        }
        $chatIds = json_decode((string) $this->getConfig('moderation_chat_ids', '[]'), true);
        if (!is_array($chatIds)) {
            Log::warning('TgAiAssistant 审核群 ID 配置无效');
            return;
        }
        if ($chatIds !== [] && !in_array((int) $msg->chat_id, array_map('intval', $chatIds), true)) {
            return;
        }
        $snapshot = (array) $msg;
        $snapshot['tg_ai_chat_title'] = mb_substr((string) ($raw['chat']['title'] ?? ''), 0, 80);
        $snapshot['tg_ai_sender_id'] = (int) $raw['from']['id'];
        $snapshot['tg_ai_sender_name'] = mb_substr(trim(
            (string) ($raw['from']['first_name'] ?? '') . ' ' . (string) ($raw['from']['last_name'] ?? '')
        ), 0, 80);
        ModerateGroupMessageJob::dispatch($snapshot);
        $msg->tg_ai_moderation_pending = true;
    }

    public function processApprovedMessage(array $snapshot): void
    {
        // Editing an old message must not trigger another reply or import a
        // second copy into the group knowledge base.
        if (!empty($snapshot['tg_ai_is_edit'])) {
            return;
        }
        $this->telegramService = new TelegramService();
        $msg = (object) $snapshot;
        $this->handleMessage(false, [$msg]);
        $this->collectGroupMemory([$msg]);
    }

    protected function reply(object $msg, string $message): void
    {
        (new \Plugin\TgAiAssistant\Services\ModerationTelegramService())->replyToMessage(
            (int) $msg->chat_id,
            $message,
            isset($msg->message_id) ? (int) $msg->message_id : null
        );
    }
}
