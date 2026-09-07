<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\Contracts\TextModel;
use App\Services\ClaudeResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * GeminiService — обёртка для Google Generative Language (Gemini).
 *
 * Возвращает ClaudeResult, совместимый с Pipeline.
 */
final class GeminiService implements TextModel
{
    private Client $http;
    /** @var array<string,mixed> */
    private array $config;
    private string $apiKey;
    private string $defaultModel;
    private string $endpointBase;

    /**
     * @param Client $http Guzzle client (можно инжектить через контейнер)
     * @param array<string,mixed> $config конфиг для Gemini (model, endpoint, pricing и т.д.)
     */
    public function __construct(Client $http, array $config = [])
    {
        $models = [
            'gemini-2.5-flash-lite',
            'gemini-2.5-flash',
            'gemini-flash-lite-latest',
            'gemini-3-flash-preview'
        ];


        $this->http = $http;
        $this->config = $config;

        // Попробуем взять ключ из стандартного места services.php, иначе из textgen
        $this->apiKey = (string) ($config['key'] ?? config('textgen.gemini_api_key') ?? '');

        if ($this->apiKey === '') {
         //   throw new RuntimeException('1111');
           // return back()->withErrors(['ai_model' => 'Selected AI provider is not configured: ' . $this->config]);
            // Не бросаем фатально — оставим, чтобы контейнер мог создаваться, но при вызове send будет ошибка
            // Можно также бросить RuntimeException здесь, если хотите fail-fast.
        }

        $this->defaultModel = (string) ($config['model'] ?? 'gemini-3-flash-preview');
        $this->endpointBase = (string) ($config['endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models');
    }

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
       /* if ($this->apiKey === '') {
            throw new RuntimeException('Gemini API key is not configured (services.gemini.key or textgen.gemini_key).');
        }*/

        // Выбираем модель из конфига, если нужно — можно передавать модель через $this->config
        $model = $this->config['model'] ?? $this->defaultModel;

        $url = rtrim($this->endpointBase, '/') . '/' . $model . ':generateContent?key=' . urlencode($this->apiKey);

        // Составляем system_instruction и contents в формате, ожидаемом API
        $systemInstruction = [
            'parts' => [
                ['text' => $rules],
            ],
        ];

        // Преобразуем $messages (role/content) в структуру contents
        $contents = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            $content = $m['content'] ?? '';
            // API ожидает массив parts
            $contents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => (string) $content],
                ],
            ];
        }

        // Map effort -> temperature/other params (приблизительно)
        $temperature = $this->mapEffortToTemperature($effort);
        $topP = $this->config['generation']['top_p'] ?? 0.95;
        $maxOutputTokens = $this->config['generation']['max_output_tokens'] ?? $maxTokens;

        $payload = [
            'system_instruction' => $systemInstruction,
            'contents' => $contents,
            'generationConfig' => [
                'response_mime_type' => 'text/plain',
                'max_output_tokens' => (int) $maxOutputTokens,
                'temperature' => (float) $temperature,
                'top_p' => (float) $topP,
                // Можно добавить другие параметры из конфига
            ],
        ];

        // Если нужны web tools — Gemini может поддерживать инструменты, но это зависит от API.
        // Мы не включаем их по умолчанию; если требуется, добавьте в $payload соответствующие поля.
        if ($withWebTools) {
            // Пример: добавить hint о GEO или включить дополнительные флаги
            $payload['web'] = ['geo' => $geo];
        }

        try {
            $response = $this->http->post($url, [
                'json' => $payload,
                'timeout' => $this->config['timeout'] ?? 120,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('HTTP error while calling Gemini: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $status = $response->getStatusCode();
        $bodyRaw = (string) $response->getBody();
        $body = json_decode($bodyRaw, true);

        if ($status >= 400) {
            $message = $body['error']['message'] ?? ($body['error'] ?? $bodyRaw);
            throw new RuntimeException(sprintf('Gemini API error: %s (status %d)', (string) $message, $status));
        }

        // Ожидаем, что ответ содержит candidates[0].content.parts[0].text или output[0].content
        $text = '';
        $stopReason = null;

        // Попробуем несколько вариантов структуры ответа (устойчивость)
        if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
            $text = (string) $body['candidates'][0]['content']['parts'][0]['text'];
        } elseif (isset($body['candidates'][0]['content'][0]['text'])) {
            $text = (string) $body['candidates'][0]['content'][0]['text'];
        } elseif (isset($body['output'][0]['content'][0]['text'])) {
            $text = (string) $body['output'][0]['content'][0]['text'];
        } elseif (isset($body['output_text'])) {
            $text = (string) $body['output_text'];
        } else {
            // Если ничего не найдено — возьмём сырое тело как fallback
            $text = trim($bodyRaw);
        }

        // Попытка извлечь usage / token counts — структура зависит от API версии
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheRead = 0;
        $cacheWrite = 0;
        $webSearches = 0;
        $costUsd = 0.0;

        // Примеры возможных мест хранения usage в ответе
        if (isset($body['candidates'][0]['metadata']['tokenUsage'])) {
            $tu = $body['candidates'][0]['metadata']['tokenUsage'];
            $inputTokens = (int) ($tu['inputTokens'] ?? $tu['input_tokens'] ?? 0);
            $outputTokens = (int) ($tu['outputTokens'] ?? $tu['output_tokens'] ?? 0);
        } elseif (isset($body['usage'])) {
            $usage = $body['usage'];
            $inputTokens = (int) ($usage['input_tokens'] ?? $usage['inputTokens'] ?? 0);
            $outputTokens = (int) ($usage['output_tokens'] ?? $usage['outputTokens'] ?? 0);
            $cacheRead = (int) ($usage['cache_read'] ?? 0);
            $cacheWrite = (int) ($usage['cache_write'] ?? 0);
            $webSearches = (int) ($usage['web_searches'] ?? $usage['server_tool_web_searches'] ?? 0);
        }

        // stopReason — если API возвращает причину остановки
        if (isset($body['candidates'][0]['metadata']['stopReason'])) {
            $stopReason = $body['candidates'][0]['metadata']['stopReason'];
        } elseif (isset($body['stop_reason'])) {
            $stopReason = $body['stop_reason'];
        }

        // Рассчитать стоимость по конфигу (если есть), иначе 0.0
        $costUsd = $this->calculateCost(
            $inputTokens,
            $outputTokens,
            $cacheRead,
            $cacheWrite
        );

        // Возвращаем ClaudeResult — совместимый DTO
        return new ClaudeResult(
            text: trim((string) $text),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheReadTokens: $cacheRead,
            cacheWriteTokens: $cacheWrite,
            webSearches: $webSearches,
            stopReason: $stopReason,
            costUsd: $costUsd,
        );
    }

    /**
     * Простейшая маппинг-логика effort -> temperature.
     */
    private function mapEffortToTemperature(string $effort): float
    {
        $effort = strtolower(trim($effort));

        return match ($effort) {
            'low' => 0.2,
            'medium' => 0.5,
            'high' => 0.8,
            default => 0.5,
        };
    }

    /**
     * Рассчитать стоимость по конфигу textgen.pricing или gemini.pricing.
     *
     * Формула аналогична ClaudeService: входные/выходные токены и кеш.
     *
     * @param int $in
     * @param int $out
     * @param int $cacheRead
     * @param int $cacheWrite
     */
    private function calculateCost(int $in, int $out, int $cacheRead, int $cacheWrite): float
    {
        // Попробуем взять прайсинг из конфигурации gemini, иначе из textgen.pricing
        $p = $this->config['pricing'] ?? config('textgen.pricing') ?? [];

        $inputPerMtok = (float) ($p['input_per_mtok'] ?? 0.0);
        $outputPerMtok = (float) ($p['output_per_mtok'] ?? 0.0);
        $cacheReadPerMtok = (float) ($p['cache_read_per_mtok'] ?? 0.0);
        $cacheWritePerMtok = (float) ($p['cache_write_per_mtok'] ?? 0.0);

        return
            ($in / 1_000_000) * $inputPerMtok
            + ($out / 1_000_000) * $outputPerMtok
            + ($cacheRead / 1_000_000) * $cacheReadPerMtok
            + ($cacheWrite / 1_000_000) * $cacheWritePerMtok;
    }
}
