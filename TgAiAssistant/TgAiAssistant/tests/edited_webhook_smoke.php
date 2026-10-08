<?php
namespace App\Services\Plugin {
    class AbstractPlugin {
        protected array $config = [];
        public function getConfig($key, $default = null) { return $this->config[$key] ?? $default; }
        public function setConfig(array $config): void { $this->config = $config; }
    }
}
namespace Plugin\TgAiAssistant\Jobs {
    class ModerateGroupMessageJob {
        public static array $messages = [];
        public static function dispatch(array $message): void { self::$messages[] = $message; }
    }
}
namespace {
    require dirname(__DIR__) . '/Plugin.php';
    class FakeEditRequest {
        public string $method = 'POST';
        public string $path = 'api/v1/guest/telegram/webhook';
        public string $token;
        public array $edit;
        public function __construct() {
            $this->token = md5('test-token');
            $this->edit = ['chat' => ['id' => -100123, 'type' => 'supergroup'],
                'from' => ['id' => 123], 'message_id' => 5, 'text' => 'edited text'];
        }
        public function isMethod($method) { return $this->method === $method; }
        public function is($path) { return $this->path === $path; }
        public function json($key) { return $this->edit; }
        public function input($key) { return $this->token; }
    }
    function request() { return $GLOBALS['editRequest']; }
    function admin_setting($key, $default = null) { return 'test-token'; }
    class EditProbe extends \Plugin\TgAiAssistant\Plugin {
        public function probe(): void { $this->handleEditedWebhook(); }
    }
    $plugin = new EditProbe();
    $plugin->setConfig(['enable_moderation' => true]);
    $GLOBALS['editRequest'] = new FakeEditRequest();
    $plugin->probe();
    $messages = \Plugin\TgAiAssistant\Jobs\ModerateGroupMessageJob::$messages;
    if (count($messages) !== 1 || empty($messages[0]['tg_ai_is_edit']) || $messages[0]['text'] !== 'edited text') {
        throw new \RuntimeException('Edited message dispatch failed');
    }
    foreach (['token', 'path', 'method', 'private', 'disabled'] as $case) {
        $GLOBALS['editRequest'] = new FakeEditRequest();
        $plugin->setConfig(['enable_moderation' => true]);
        if ($case === 'token') { request()->token = 'invalid'; }
        if ($case === 'path') { request()->path = 'api/v1/other'; }
        if ($case === 'method') { request()->method = 'GET'; }
        if ($case === 'private') { request()->edit['chat']['type'] = 'private'; }
        if ($case === 'disabled') { $plugin->setConfig(['enable_moderation' => true, 'moderation_watch_edits' => false]); }
        $plugin->probe();
        if (count(\Plugin\TgAiAssistant\Jobs\ModerateGroupMessageJob::$messages) !== 1) {
            throw new \RuntimeException('Unexpected dispatch: ' . $case);
        }
    }
    $plugin->processApprovedMessage(['tg_ai_is_edit' => true]);
    echo "PASS: edit dispatch, authentication, route, scope and disabled switch\n";
}
