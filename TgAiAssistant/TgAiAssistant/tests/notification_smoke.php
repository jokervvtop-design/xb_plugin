<?php

// php plugins/TgAiAssistant/tests/notification_smoke.php
require dirname(__DIR__) . '/Services/ModerationNotifier.php';

use Plugin\TgAiAssistant\Services\ModerationNotifier;

$notifier = new ModerationNotifier();
foreach (['[]' => [], '[123,"456",123]' => [123, 456]] as $raw => $expected) {
    if ($notifier->parseRecipients($raw) !== $expected) {
        throw new RuntimeException('Recipient parsing failed');
    }
}
foreach (['invalid', '{}', 'null', '[0]', '[-100123]', '[true]', '[1.2]', '["@bot"]', '["123oops"]', '["999999999999999999999999"]'] as $raw) {
    try {
        $notifier->parseRecipients($raw);
    } catch (RuntimeException $e) {
        continue;
    }
    throw new RuntimeException('Invalid recipient accepted: ' . $raw);
}
$message = ['chat_id' => -100123, 'message_id' => 5, 'tg_ai_sender_id' => 123,
    'tg_ai_sender_name' => '测试用户', 'tg_ai_chat_title' => '测试群', 'text' => str_repeat('中', 4096)];
$audit = ['model' => 'test-model', 'threshold' => 0.9, 'result' => '违规',
    'decision' => ['violation' => true, 'category' => 'gambling', 'confidence' => 0.95],
    'enforcement_attempted' => true, 'minutes' => 60, 'deleted' => true, 'muted' => false];
$notice = $notifier->formatNotice($message, $audit, false);
foreach (['测试群', '测试用户', '删除消息：成功', '禁言：失败', '群内提示：失败', '[内容已截断]'] as $part) {
    if (!str_contains($notice, $part)) {
        throw new RuntimeException('Notification omitted actual result: ' . $part);
    }
}
if (mb_strlen($notice) >= 4000 || str_contains($notice, '禁言：成功')) {
    throw new RuntimeException('Notification overflow or false success');
}
$debug = $notifier->formatNotice($message, ['result' => '审核异常，未执行处罚',
    'error' => 'Telegram getChatMember 失败（HTTP 400）：Bad Request: chat not found'], true);
if (!str_contains($debug, 'Debug') || !str_contains($debug, '审核异常') || str_contains($debug, '禁言：成功')) {
    throw new RuntimeException('Invalid failed audit notification');
}
if (!str_contains($debug, 'chat not found')) {
    throw new RuntimeException('Preflight diagnostic missing');
}
echo "PASS: recipient validation, actual enforcement results, truncation and debug failure report\n";
