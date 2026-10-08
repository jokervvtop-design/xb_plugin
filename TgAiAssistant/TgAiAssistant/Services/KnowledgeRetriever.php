<?php

namespace Plugin\TgAiAssistant\Services;

use App\Models\Knowledge;
use Plugin\TgAiAssistant\Models\AiKnowledge;

class KnowledgeRetriever
{
    public function retrieve(
        string $query,
        int $limit = 3,
        string $language = 'zh-CN',
        array $customArticles = []
    ): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $articles = [];

        $xboardItems = Knowledge::query()
            ->where('show', 1)
            ->where('language', $language)
            ->where(function ($builder) use ($query) {
                $builder->where('title', 'like', '%' . $query . '%')
                    ->orWhere('body', 'like', '%' . $query . '%');
            })
            ->orderBy('sort')
            ->limit($limit)
            ->get(['title', 'body']);

        foreach ($xboardItems as $item) {
            $articles[] = [
                'title' => $item->title,
                'content' => $this->cleanContent($item->body),
                'source' => 'xboard',
            ];
        }

        $remaining = $limit - count($articles);
        if ($remaining > 0 && !empty($customArticles)) {
            foreach ($this->matchCustomArticles($query, $customArticles, $remaining) as $item) {
                $articles[] = $item;
            }
        }

        $remaining = $limit - count($articles);
        if ($remaining > 0 && class_exists(AiKnowledge::class)) {
            $pluginItems = AiKnowledge::query()
                ->where('show', 1)
                ->where(function ($builder) use ($query) {
                    $builder->where('title', 'like', '%' . $query . '%')
                        ->orWhere('content', 'like', '%' . $query . '%');
                })
                ->orderByDesc('id')
                ->limit($remaining)
                ->get(['title', 'content', 'source']);

            foreach ($pluginItems as $item) {
                $articles[] = [
                    'title' => $item->title,
                    'content' => $this->cleanContent($item->content),
                    'source' => $item->source ?? 'plugin',
                ];
            }
        }

        return $articles;
    }

    public static function parseCustomKnowledge(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '[]') {
            return [];
        }

        $items = json_decode($raw, true);
        if (!is_array($items)) {
            return [];
        }

        $articles = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $content = trim((string) ($item['content'] ?? ''));
            if ($title === '' || $content === '') {
                continue;
            }

            $articles[] = [
                'title' => $title,
                'content' => $content,
                'source' => 'config',
            ];
        }

        return $articles;
    }

    protected function matchCustomArticles(string $query, array $articles, int $limit): array
    {
        $matched = [];
        foreach ($articles as $article) {
            $title = (string) ($article['title'] ?? '');
            $content = (string) ($article['content'] ?? '');
            if (
                mb_stripos($title, $query) !== false
                || mb_stripos($content, $query) !== false
            ) {
                $matched[] = [
                    'title' => $title,
                    'content' => $this->cleanContent($content),
                    'source' => $article['source'] ?? 'config',
                ];
            }

            if (count($matched) >= $limit) {
                break;
            }
        }

        return $matched;
    }

    public function formatForPrompt(array $articles): string
    {
        if (empty($articles)) {
            return '';
        }

        $lines = ["【知识库参考】"];
        foreach ($articles as $index => $article) {
            $lines[] = sprintf(
                "%d. %s\n%s",
                $index + 1,
                $article['title'],
                $article['content']
            );
        }

        return implode("\n\n", $lines);
    }

    protected function cleanContent(string $content): string
    {
        $content = strip_tags($content);
        $content = preg_replace('/<!--access start-->.*?<!--access end-->/s', '', $content) ?? $content;
        $content = preg_replace('/\s+/', ' ', $content) ?? $content;

        return mb_substr(trim($content), 0, 800);
    }
}
