<?php

namespace Plugin\TgAiAssistant\Services;

use App\Services\TelegramService;
use Illuminate\Support\Facades\Http;

class ModerationTelegramService extends TelegramService
{
    public function __construct()
    {
        parent::__construct();
        $this->http = Http::timeout(10)->withHeaders(['Accept' => 'application/json']);
    }

    public function member(int $chatId, int $userId): object
    {
        return $this->request('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId])->result;
    }

    public function replyToMessage(int $chatId, string $text, ?int $messageId = null): void
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($messageId !== null && $messageId > 0) {
            $params['reply_parameters'] = json_encode([
                'message_id' => $messageId,
                'allow_sending_without_reply' => true,
            ]);
        }
        $this->request('sendMessage', $params);
    }

    protected function request(string $method, array $params = []): object
    {
        // Omit optional null values: form encoding can turn null parse_mode into
        // an invalid empty string. Preserve false permissions and numeric zero.
        $params = array_filter($params, static fn ($value) => $value !== null);
        if (isset($params['parse_mode']) && $params['parse_mode'] === '') {
            unset($params['parse_mode']);
        }
        try {
            $response = $this->http->post($this->apiUrl . $method, $params);
        } catch (\Throwable $e) {
            // Transport exception messages may contain the token-bearing request URL.
            throw new \RuntimeException("Telegram {$method} 连接失败或超时");
        }
        $data = $response->object();
        if (!$response->successful() || !is_object($data) || empty($data->ok)) {
            $description = is_object($data) && is_string($data->description ?? null)
                ? mb_substr($data->description, 0, 300) : '无效的 Telegram API 响应';
            throw new \RuntimeException("Telegram {$method} 失败（HTTP {$response->status()}）：{$description}");
        }
        return $data;
    }

    public function deleteMessage(int $chatId, int $messageId): void
    {
        $this->request('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    public function mute(int $chatId, int $userId, int $seconds): void
    {
        $this->request('restrictChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'permissions' => json_encode([
                'can_send_messages' => false,
                'can_send_audios' => false,
                'can_send_documents' => false,
                'can_send_photos' => false,
                'can_send_videos' => false,
                'can_send_video_notes' => false,
                'can_send_voice_notes' => false,
                'can_send_polls' => false,
                'can_send_other_messages' => false,
                'can_add_web_page_previews' => false,
            ]),
            'use_independent_chat_permissions' => true,
            'until_date' => time() + $seconds,
        ]);
    }
}
