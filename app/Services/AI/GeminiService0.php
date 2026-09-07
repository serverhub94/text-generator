<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Services\Contracts\TextModel;
use App\Services\ClaudeResult;

final class GeminiService0 implements TextModel
{
    public function __construct(
        // inject http client, config array, logger и т.д.
        private readonly \GuzzleHttp\Client $http,
        private readonly array $config,
    ) {}

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     */
    public function send(
        string $rules,
        array $messages,
        string $effort,
        int $maxTokens,
        bool $withWebTools = false,
        ?string $geo = null,
    ): ClaudeResult {
        // 1) Подготовьте payload под Gemini API (пример — псевдокод)
        $payload = [
            'model' => $this->config['model'] ?? 'gemini-default',
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => $this->mapEffortToTemperature($effort),
            // если нужны web tools — добавьте соответствующие флаги
        ];

        // 2) Выполните HTTP/SDK вызов (обработайте ошибки, таймауты)
        $resp = $this->http->post($this->config['endpoint'], [
            'json' => $payload,
            'timeout' => $this->config['timeout'] ?? 60,
        ]);

        $data = json_decode((string) $resp->getBody(), true);

        // 3) Преобразуйте ответ Gemini в ClaudeResult (важно: поля совпадают)
        $text = $data['output_text'] ?? ($data['choices'][0]['message']['content'] ?? '');
        $usage = $data['usage'] ?? ['input_tokens' => 0, 'output_tokens' => 0];
        $stopReason = $data['stop_reason'] ?? null;
        $cacheRead = $data['usage']['cache_read'] ?? 0;
        $cacheWrite = $data['usage']['cache_write'] ?? 0;
        $searches = $data['usage']['web_searches'] ?? 0;
        $cost = $this->calculateCost(
            $usage['input_tokens'] ?? 0,
            $usage['output_tokens'] ?? 0,
            $cacheRead,
            $cacheWrite
        );

        return new ClaudeResult(
            text: trim((string) $text),
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cacheReadTokens: (int) $cacheRead,
            cacheWriteTokens: (int) $cacheWrite,
            webSearches: (int) $searches,
            stopReason: $stopReason,
            costUsd: $cost,
        );
    }

    private function mapEffortToTemperature(string $effort): float
    {
        // Простейшая маппинг‑логика; адаптируйте под ваши требования
        return match ($effort) {
            'low' => 0.2,
            'medium' => 0.5,
            'high' => 0.8,
            default => 0.5,
        };
    }

    private function calculateCost(int $in, int $out, int $cacheRead, int $cacheWrite): float
    {
        $p = $this->config['pricing'] ?? [];
        // Простейшая формула; подгоните под реальные тарифы Gemini
        return ($in / 1_000_000) * ($p['input_per_mtok'] ?? 0.0)
            + ($out / 1_000_000) * ($p['output_per_mtok'] ?? 0.0)
            + ($cacheRead / 1_000_000) * ($p['cache_read_per_mtok'] ?? 0.0)
            + ($cacheWrite / 1_000_000) * ($p['cache_write_per_mtok'] ?? 0.0);
    }
}
