<?php

// Standalone regression: php plugins/TgAiAssistant/tests/telegram_params_smoke.php
namespace App\Services {
    class TelegramService
    {
        protected $http;
        protected string $apiUrl = 'https://example.invalid/';
        public function __construct() {}
    }
}
namespace Illuminate\Support\Facades {
    class Http
    {
        public static array $params = [];
        public static function timeout(int $seconds): self { return new self(); }
        public function withHeaders(array $headers): self { return $this; }
        public function post(string $url, array $params): object
        {
            self::$params = $params;
            return new class {
                public function object(): object { return (object) ['ok' => true]; }
                public function successful(): bool { return true; }
            };
        }
    }
}
namespace {
    require dirname(__DIR__) . '/Services/ModerationTelegramService.php';
    class ProbeTelegram extends \Plugin\TgAiAssistant\Services\ModerationTelegramService
    {
        public function probe(array $params): void { $this->request('sendMessage', $params); }
    }
    $service = new ProbeTelegram();
    foreach ([null, ''] as $mode) {
        $service->probe(['chat_id' => 123, 'text' => 'plain', 'parse_mode' => $mode,
            'optional' => null, 'permission' => false, 'zero' => 0]);
        $params = \Illuminate\Support\Facades\Http::$params;
        if (array_key_exists('parse_mode', $params) || array_key_exists('optional', $params)
            || $params['permission'] !== false || $params['zero'] !== 0) {
            throw new \RuntimeException('Invalid optional parameter filtering');
        }
    }
    $service->probe(['chat_id' => 123, 'text' => 'formatted', 'parse_mode' => 'HTML']);
    if (\Illuminate\Support\Facades\Http::$params['parse_mode'] !== 'HTML') {
        throw new \RuntimeException('Explicit parse mode removed');
    }
    $service->replyToMessage(-100123, 'answer', 42);
    $params = \Illuminate\Support\Facades\Http::$params;
    $reply = json_decode($params['reply_parameters'] ?? '{}', true);
    if (($reply['message_id'] ?? null) !== 42 || ($reply['allow_sending_without_reply'] ?? null) !== true
        || isset($params['parse_mode'])) {
        throw new \RuntimeException('Quoted reply parameters invalid');
    }
    $service->replyToMessage(-100123, 'old queued answer');
    if (isset(\Illuminate\Support\Facades\Http::$params['reply_parameters'])) {
        throw new \RuntimeException('Unexpected quote for legacy message');
    }
    echo "PASS: null/empty parse_mode omitted, false/zero and explicit HTML preserved\n";
}
