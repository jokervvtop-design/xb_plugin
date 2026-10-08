<?php

namespace Plugin\TgGroupCheckin;

use App\Models\Plugin as PluginModel;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
    protected TelegramService $telegramService;

    public function boot(): void
    {
        if (!$this->isTelegramPluginEnabled()) {
            Log::warning('Telegram 群签到插件已启用，但 Telegram Bot 集成插件未启用');
            return;
        }

        $this->telegramService = new TelegramService();
        $this->filter('telegram.message.handle', [$this, 'handleMessage'], 5);
    }

    protected function isTelegramPluginEnabled(): bool
    {
        return PluginModel::where('code', 'telegram')->where('is_enabled', true)->exists();
    }

    public function handleMessage(bool $handled, array $data): bool
    {
        if ($handled) {
            return $handled;
        }

        [$msg] = $data;

        if (!$this->getConfig('enable_checkin', true)) {
            return $handled;
        }

        if ($msg->message_type !== 'message') {
            return $handled;
        }

        if ($msg->is_private && !$this->getConfig('enable_private_checkin', true)) {
            return $handled;
        }

        if (!$msg->is_private && !$this->getConfig('enable_group_checkin', true)) {
            return $handled;
        }

        if (!$this->isCheckinMessage($msg->text ?? '')) {
            return $handled;
        }

        $fromId = $this->resolveSenderTelegramId();
        if (!$fromId) {
            return $handled;
        }

        $this->processCheckin($msg, $fromId);
        return true;
    }

    protected function isCheckinMessage(string $text): bool
    {
        $keyword = trim((string) $this->getConfig('checkin_keyword', '签到'));
        return trim($text) === $keyword;
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

    protected function processCheckin(object $msg, int $telegramUserId): void
    {
        $user = User::where('telegram_id', $telegramUserId)->first();
        if (!$user) {
            $this->reply($msg, $this->getConfig(
                'reply_not_bound',
                '❌ 您尚未绑定账号，请私聊机器人发送 /bind 绑定后再签到'
            ));
            return;
        }

        if ($this->hasCheckedInToday($telegramUserId)) {
            $this->reply($msg, $this->getConfig(
                'reply_already_checked',
                '⏰ 今日已签到，请明天再来'
            ));
            return;
        }

        $this->markCheckedInToday($telegramUserId);

        $probability = max(0, min(100, (int) $this->getConfig('reward_probability', 30)));
        if ($probability <= 0 || random_int(1, 100) > $probability) {
            $this->reply($msg, $this->getConfig(
                'reply_miss',
                '😢 签到未中奖，祝下次好运'
            ));
            return;
        }

        $trafficMb = $this->resolveRewardTrafficMb();
        if ($trafficMb <= 0) {
            $this->reply($msg, $this->getConfig(
                'reply_miss',
                '😢 签到未中奖，祝下次好运'
            ));
            return;
        }

        $user->transfer_enable = ($user->transfer_enable ?? 0) + ($trafficMb * 1048576);
        if (!$user->save()) {
            Log::error('Telegram 群签到发放流量失败', [
                'user_id' => $user->id,
                'telegram_id' => $telegramUserId,
            ]);
            $this->reply($msg, '签到失败，请稍后重试');
            return;
        }

        $reply = str_replace(
            '{traffic}',
            (string) $trafficMb,
            $this->getConfig('reply_success', '🎉 签到成功！获得 {traffic}MB 流量')
        );
        $this->reply($msg, $reply);
    }

    protected function resolveRewardTrafficMb(): int
    {
        $min = max(0, (int) $this->getConfig('reward_traffic_min_mb', 50));
        $max = max(0, (int) $this->getConfig('reward_traffic_max_mb', 200));

        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        if ($max <= 0) {
            return 0;
        }

        if ($min === $max) {
            return $min;
        }

        return random_int($min, $max);
    }

    protected function hasCheckedInToday(int $telegramUserId): bool
    {
        if (!$this->getConfig('daily_once', true)) {
            return false;
        }

        return Cache::has($this->dailyCacheKey($telegramUserId));
    }

    protected function markCheckedInToday(int $telegramUserId): void
    {
        if (!$this->getConfig('daily_once', true)) {
            return;
        }

        Cache::put($this->dailyCacheKey($telegramUserId), 1, now()->endOfDay());
    }

    protected function dailyCacheKey(int $telegramUserId): string
    {
        return 'tg_group_checkin_' . $telegramUserId . '_' . date('Ymd');
    }

    protected function reply(object $msg, string $message): void
    {
        $this->telegramService->sendMessage($msg->chat_id, $message);
    }
}
