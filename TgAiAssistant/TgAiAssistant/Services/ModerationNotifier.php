<?php

namespace Plugin\TgAiAssistant\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Plugin\TgAiAssistant\Jobs\SendModerationNoticeJob;

class ModerationNotifier
{
    public function notify(array $config, array $message, array $audit): void
    {
        $debug = !empty($config['moderation_debug']);
        if (!$debug && (empty($audit['enforcement_attempted'])
            || !($config['moderation_notify_admin'] ?? true))) {
            return;
        }

        try {
            $recipients = $this->parseRecipients((string) ($config['admin_telegram_ids'] ?? '[]'));
            if ($recipients === []) {
                $recipients = User::where('is_admin', 1)->whereNotNull('telegram_id')
                    ->pluck('telegram_id')->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $id > 0)->unique()->values()->all();
            }
            if ($recipients === []) {
                Log::warning('TgAiAssistant 审核通知无接收人，请填写通知 Telegram ID 或绑定管理员账号');
                return;
            }
            $text = $this->formatNotice($message, $audit, $debug);
            foreach ($recipients as $id) {
                SendModerationNoticeJob::dispatch($id, $text);
            }
        } catch (\Throwable $e) {
            Log::warning('TgAiAssistant 管理员审核通知投递失败', ['error' => $e->getMessage()]);
        }
    }

    public function parseRecipients(string $raw): array
    {
        $ids = json_decode($raw, true);
        if (!str_starts_with(ltrim($raw), '[') || !is_array($ids) || !array_is_list($ids)) {
            throw new \RuntimeException('通知 Telegram ID 必须为 JSON 数组');
        }
        $result = [];
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id))
                || !preg_match('/^[1-9][0-9]*$/', (string) $id)
                || filter_var($id, FILTER_VALIDATE_INT) === false) {
                throw new \RuntimeException('通知 Telegram ID 必须为正整数个人 ID');
            }
            $result[] = (int) $id;
        }
        return array_values(array_unique($result));
    }

    public function formatNotice(array $message, array $audit, bool $debug): string
    {
        $clean = static function (string $text, int $limit): string {
            $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? '';
            return mb_substr($text, 0, $limit);
        };
        $decision = $audit['decision'] ?? null;
        $categories = ['porn' => '色情', 'gambling' => '赌博', 'drugs' => '毒品',
            'explosives' => '爆炸物', 'other' => '群规', 'none' => '无'];
        $lines = [
            $debug ? '🔍 群聊 AI 审核 Debug' : '⚠️ 群聊违规处理通知',
            '群：' . $clean((string) ($message['tg_ai_chat_title'] ?? ''), 80) . '（' . $message['chat_id'] . '）',
            '用户：' . $clean((string) ($message['tg_ai_sender_name'] ?? ''), 80) . '（ID：' . $message['tg_ai_sender_id'] . '）',
            '消息 ID：' . $message['message_id'],
            '消息类型：' . (!empty($message['tg_ai_is_edit']) ? '编辑后的消息' : '新消息'),
            '模型：' . $clean((string) ($audit['model'] ?? ''), 100),
            '结果：' . ($audit['result'] ?? '审核异常'),
        ];
        if (!empty($audit['error'])) {
            $lines[] = '错误：' . $clean((string) $audit['error'], 400);
        }
        if (is_array($decision)) {
            $lines[] = 'AI 判定：' . ($decision['violation'] ? '违规' : '正常')
                . '；类别：' . ($categories[$decision['category']] ?? '未知')
                . '；置信度：' . $decision['confidence'] . '；门槛：' . $audit['threshold'];
        }
        if (!empty($audit['enforcement_attempted'])) {
            $lines[] = '删除消息：' . (!empty($audit['deleted']) ? '成功' : '失败');
            $lines[] = '禁言：' . (!empty($audit['muted']) ? '成功，' . $audit['minutes'] . ' 分钟' : '失败');
            $lines[] = '群内提示：' . (!empty($audit['group_notice_sent']) ? '成功' : '失败');
        }
        $text = (string) ($message['text'] ?? '');
        $lines[] = "原消息：\n" . $clean($text, 1800)
            . (mb_strlen($text) > 1800 ? "\n[内容已截断]" : '');
        return implode("\n", $lines);
    }
}
