<?php

namespace Plugin\TgAiAssistant\Jobs;

use App\Models\Plugin as PluginModel;
use App\Models\User;
use Plugin\TgAiAssistant\Services\ModerationTelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Plugin\TgAiAssistant\Models\AiConversation;
use Plugin\TgAiAssistant\Services\KnowledgeRetriever;
use Plugin\TgAiAssistant\Services\LlmClient;
use Plugin\TgAiAssistant\Services\UserContextBuilder;

class ProcessAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;
    protected ?int $replyMessageId = null;

    public function __construct(
        protected int $chatId,
        protected string $question,
        protected ?int $telegramUserId = null,
        ?int $replyMessageId = null
    ) {
        $this->replyMessageId = $replyMessageId;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $config = $this->loadPluginConfig();
        if (empty($config)) {
            return;
        }

        $apiKey = trim((string) ($config['llm_api_key'] ?? ''));
        if ($apiKey === '') {
            $this->sendReply('AI 服务未配置 API Key，请联系管理员');
            return;
        }

        try {
            $messages = $this->buildMessages($config);
            $client = new LlmClient(
                (string) ($config['llm_api_base'] ?? 'https://api.openai.com/v1'),
                $apiKey,
                (string) ($config['llm_model'] ?? 'gpt-4o-mini'),
                (int) ($config['max_tokens'] ?? 1024)
            );

            $answer = $client->chat($messages);
            $this->saveConversation('user', $this->question);
            $this->saveConversation('assistant', $answer);
            $this->sendReply($answer);
        } catch (\Throwable $e) {
            Log::error('TgAiAssistant 生成回复失败', [
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);
            $this->sendReply('抱歉，AI 暂时无法回答，请稍后重试');
        }
    }

    protected function loadPluginConfig(): array
    {
        $plugin = PluginModel::where('code', 'tg_ai_assistant')
            ->where('is_enabled', true)
            ->first();

        if (!$plugin || !$plugin->config) {
            return [];
        }

        $config = json_decode($plugin->config, true);
        return is_array($config) ? $config : [];
    }

    protected function buildMessages(array $config): array
    {
        $systemParts = [
            (string) ($config['system_prompt'] ?? '你是 XBoard 专属 AI 客服助手。'),
        ];

        if ($this->telegramUserId) {
            $user = User::where('telegram_id', $this->telegramUserId)->first();
            $systemParts[] = (new UserContextBuilder())->build($user);
        }

        if (!empty($config['enable_rag'])) {
            $retriever = new KnowledgeRetriever();
            $customArticles = KnowledgeRetriever::parseCustomKnowledge(
                (string) ($config['custom_knowledge'] ?? '')
            );
            $articles = $retriever->retrieve(
                $this->question,
                (int) ($config['rag_max_articles'] ?? 3),
                'zh-CN',
                $customArticles
            );
            $ragText = $retriever->formatForPrompt($articles);
            if ($ragText !== '') {
                $systemParts[] = $ragText;
            }
        }

        $messages = [
            ['role' => 'system', 'content' => implode("\n\n", $systemParts)],
        ];

        $historyRounds = max(0, (int) ($config['max_history_rounds'] ?? 5));
        if ($historyRounds > 0) {
            $history = AiConversation::query()
                ->where('chat_id', $this->chatId)
                ->whereIn('role', ['user', 'assistant'])
                ->orderByDesc('id')
                ->limit($historyRounds * 2)
                ->get(['role', 'content'])
                ->reverse()
                ->values();

            foreach ($history as $item) {
                $messages[] = [
                    'role' => $item->role,
                    'content' => $item->content,
                ];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $this->question];

        return $messages;
    }

    protected function saveConversation(string $role, string $content): void
    {
        AiConversation::create([
            'chat_id' => $this->chatId,
            'role' => $role,
            'content' => mb_substr($content, 0, 4000),
            'created_at' => time(),
        ]);
    }

    protected function sendReply(string $text): void
    {
        $telegramService = new ModerationTelegramService();
        $chunks = $this->splitMessage($text);
        foreach ($chunks as $chunk) {
            $telegramService->replyToMessage($this->chatId, $chunk, $this->replyMessageId);
        }
    }

    protected function splitMessage(string $text, int $limit = 4000): array
    {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        while (mb_strlen($text) > 0) {
            $chunks[] = mb_substr($text, 0, $limit);
            $text = mb_substr($text, $limit);
        }

        return $chunks;
    }
}
