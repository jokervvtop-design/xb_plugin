<?php

namespace Plugin\TgAiAssistant\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugin\TgAiAssistant\Services\ModerationTelegramService;

class SendModerationNoticeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 20;
    public int $backoff = 10;

    public function __construct(protected int $telegramId, protected string $text)
    {
        $this->onQueue('send_telegram');
    }

    public function handle(): void
    {
        // Plain text avoids interpreting user content as Telegram Markdown.
        (new ModerationTelegramService())->sendMessage($this->telegramId, $this->text);
    }
}
