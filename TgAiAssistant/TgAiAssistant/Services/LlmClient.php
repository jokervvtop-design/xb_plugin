<?php

namespace Plugin\TgAiAssistant\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LlmClient
{
    protected string $apiBase;
    protected string $apiKey;
    protected string $model;
    protected int $maxTokens;

    public function __construct(string $apiBase, string $apiKey, string $model, int $maxTokens = 1024)
    {
        $this->apiBase = rtrim($apiBase, '/');
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->maxTokens = $maxTokens;
    }

    public function chat(array $messages): string
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post($this->apiBase . '/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => $this->maxTokens,
                'temperature' => 0.7,
            ]);

        if (!$response->successful()) {
            Log::error('TgAiAssistant LLM 请求失败', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('AI 服务暂时不可用，请稍后重试');
        }

        $content = $response->json('choices.0.message.content');
        if (!is_string($content) || $content === '') {
            throw new \RuntimeException('AI 返回内容为空');
        }

        return trim($content);
    }
}
