<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Services\PromptRepository;
use App\Services\ModelResult; // если у вас общий DTO
use RuntimeException;
use Psr\Log\LoggerInterface;

final class GeminiPipeline
{
    /** @var callable(string): void */
    private $onStage;

    /** @var callable(string, string, array, float): void */
    private $onStageDone;

    public function __construct(
        private readonly GeminiService $client,
        private readonly PromptRepository $prompts,
        private readonly array $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->onStage = static fn(string $s) => null;
        $this->onStageDone = static fn(string $s, string $t, array $u, float $c) => null;
    }

    public function onStage(callable $cb): self
    {
        $this->onStage = $cb;
        return $this;
    }

    public function onStageDone(callable $cb): self
    {
        $this->onStageDone = $cb;
        return $this;
    }

    /**
     * Минимальный run: выполняет те же стадии, что и режим, но без сложных переписок.
     * Ожидает $input['mode'] и другие поля, как основной Pipeline.
     *
     * @param array<string,mixed> $input
     * @return array{stages: array<string,string>, article:string, article_meta:array<string,string>, usage:array<string,int>, cost:float, warnings:array<int,array>}
     */
    public function run(array $input): array
    {
        $modes = $this->config['modes'] ?? [];
        $modeKey = $input['mode'] ?? 'v1';
        $mode = $modes[$modeKey] ?? $modes['v1'] ?? ['stages' => ['article'], 'max_tokens' => 16000, 'effort' => 'low'];

        $vars = $this->buildVars($input);
        $rules = $this->prompts->rules($vars);

        $messages = [];
        $outputs = [];
        $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'searches' => 0];
        $cost = 0.0;
        $warnings = [];

        foreach ($mode['stages'] as $stage) {
            ($this->onStage)($stage);

            $instructions = $this->prompts->stage($stage, $vars);
            $messages[] = [
                'role' => 'user',
                'content' => $messages === [] ? $this->inputBlock($input, $vars) . "\n\n---\n\n" . $instructions : $instructions,
            ];

            // Вызов GeminiService::send — ожидаем, что он возвращает ModelResult/GeminiResult
            $result = $this->client->send(
                rules: $rules,
                messages: $messages,
                effort: (string) ($mode['effort'] ?? 'low'),
                maxTokens: (int) ($mode['max_tokens'] ?? 16000),
                withWebTools: in_array($stage, ['research','paa','entities'], true),
                geo: (string) ($input['geo'] ?? '')
            );

            // логируем кратко
            $this->logger?->info('GeminiPipeline stage result', ['stage'=>$stage, 'text_len'=>strlen($result->text), 'pid'=>getmypid()]);

            // guard
            if ($result->isRefusal()) {
                throw new RuntimeException("Gemini refused stage {$stage}");
            }
            if (trim($result->text) === '') {
                throw new RuntimeException("Gemini returned empty text for stage {$stage}");
            }

            $messages[] = ['role' => 'assistant', 'content' => $result->text];
            $outputs[$stage] = $result->text;

            // accumulate
            $usage['input'] += $result->inputTokens;
            $usage['output'] += $result->outputTokens;
            $usage['cache_read'] += $result->cacheReadTokens;
            $usage['cache_write'] += $result->cacheWriteTokens;
            $usage['searches'] += $result->webSearches;
            $cost += $result->costUsd;

            ($this->onStageDone)($stage, $result->text, $usage, $cost);

            if ($result->isTruncated()) {
                $warnings[] = [
                    'stage' => $stage,
                    'reason' => 'max_tokens',
                    'message' => "Stage {$stage} truncated by tokens",
                    'time' => (string) now(),
                ];
                break;
            }
        }

        // article parsing reuse if you have ArticleDocument
        $article = \App\Support\ArticleDocument::parse($outputs['article'] ?? '');

        return [
            'stages' => $outputs,
            'article' => $article->html,
            'article_meta' => [
                'title' => $article->title,
                'description' => $article->description,
                'url' => $article->url,
                'notes' => $article->notes,
            ],
            'usage' => $usage,
            'cost' => round($cost, 4),
            'warnings' => $warnings,
        ];
    }

    private function buildVars(array $input): array
    {
        return [
            'target_query' => trim((string) ($input['target_query'] ?? '')),
            'geo' => strtoupper(trim((string) ($input['geo'] ?? ''))),
            'language' => trim((string) ($input['language'] ?? '')),
            'domain' => trim((string) ($input['domain'] ?? '')),
        ];
    }

    private function inputBlock(array $input, array $vars): string
    {
        // можно переиспользовать код из основного Pipeline::inputBlock
        return (new \App\Services\Pipeline($this->client, $this->prompts, $this->config))->inputBlock($input, $vars);
    }
}
