<?php

declare(strict_types=1);

namespace App\Services;

use Anthropic\Client;
use App\Services\Contracts\TextModel;
use Anthropic\Lib\Streaming\MessageAccumulator;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\UserLocation;
use Anthropic\Messages\WebFetchTool20260209;
use Anthropic\Messages\WebSearchTool20260209;

/**
 * Обёртка над Anthropic SDK под нужды конвейера.
 *
 * Два решения, которые стоит держать в голове при правках:
 *
 * 1. Всегда стримим. Стадии генерируют десятки тысяч токенов, а обычный
 *    запрос упирается в HTTP-таймаут. События сворачиваем аккумулятором SDK
 *    и получаем на выходе обычный Message — стрим здесь ради надёжности,
 *    а не ради вывода по буквам.
 *
 * 2. Разговор растёт. Стадии идут одной перепиской: ресёрч, потом ТЗ, потом
 *    текст. За счёт этого модель на каждой следующей стадии видит всё, что
 *    сама выяснила раньше, а стабильный префикс переписки попадает в кеш —
 *    поздние стадии стоят заметно дешевле ранних.
 */
final class ClaudeService implements TextModel
{
    public function __construct(
        private readonly Client $client,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     */

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
        // Максимум дозапросов: сначала из конфига, иначе из env, иначе 5
        $maxContinuations = (int) ($this->config['max_continuations'] ?? env('TEXTGEN_MAX_CONTINUATIONS', 5));
        if ($maxContinuations < 0) {
            $maxContinuations = 5;
        }

        // Накопители для суммирования метрик и текста
        $totalInput = 0;
        $totalOutput = 0;
        $totalCacheRead = 0;
        $totalCacheWrite = 0;
        $totalSearches = 0;
        $totalCost = 0.0;
        $allText = '';
        $finalStopReason = null;

        // Исходные сообщения — будем дополнять ассистентским блоком при продолжениях
        $currentMessages = $messages;

        $continuation = 0;

        do {
            // Создаём стрим (как раньше)
            $stream = $this->client->messages->createStream(
                maxTokens: $maxTokens,
                messages: $currentMessages,
                model: (string) $this->config['model'],
                cacheControl: ['type' => 'ephemeral', 'ttl' => '1h'],
                outputConfig: ['effort' => $effort],
                system: [
                    ['type' => 'text', 'text' => $rules],
                ],
                thinking: ['type' => 'adaptive'],
                tools: $withWebTools ? $this->webTools($geo) : null,
            );

            $accumulator = MessageAccumulator::forMessages();

            foreach ($stream as $event) {
                $accumulator->accumulate($event);
            }

            $message = $accumulator->message();

            // Собираем текст из текстовых блоков и добавляем к общему
            $pieceText = '';
            foreach ($message->content as $block) {
                if ($block instanceof TextBlock) {
                    $pieceText .= $block->text;
                }
            }
            $pieceText = trim($pieceText);
            if ($pieceText !== '') {
                // Добавляем с разделителем, если уже есть текст
                $allText .= $allText === '' ? $pieceText : ("\n" . $pieceText);
            }

            // Накопление usage/cost из текущего сообщения
            $usage = $message->usage;
            $cacheRead = $usage->cacheReadInputTokens ?? 0;
            $cacheWrite = $usage->cacheCreationInputTokens ?? 0;
            $searches = $usage->serverToolUse?->webSearchRequests ?? 0;

            $totalInput += (int) ($usage->inputTokens ?? 0);
            $totalOutput += (int) ($usage->outputTokens ?? 0);
            $totalCacheRead += (int) $cacheRead;
            $totalCacheWrite += (int) $cacheWrite;
            $totalSearches += (int) $searches;

            $totalCost += $this->cost(
                (int) ($usage->inputTokens ?? 0),
                (int) ($usage->outputTokens ?? 0),
                (int) $cacheRead,
                (int) $cacheWrite,
            );

            $finalStopReason = $message->stopReason;

            // Если модель вернула pause_turn — подготовить дозапрос:
            // добавляем ассистентский ход с полным набором блоков ответа (message->content)
            if ($finalStopReason === 'pause_turn') {
                $continuation++;

                if ($continuation > $maxContinuations) {
                    throw new \RuntimeException("Exceeded max continuations ({$maxContinuations}) while handling pause_turn.");
                }

                // Формируем ассистентский блок: role assistant, content — оригинальные блоки
                // Важно: не добавляем никаких текстовых сообщений, только блоки ответа модели.
                $assistantBlock = [
                    'role' => 'assistant',
                    'content' => $message->content,
                ];

                // Для следующего запроса используем исходные сообщения + ассистентский блок
                // (не добавляем дополнительные user/assistant тексты)
                $currentMessages = array_merge($messages, [$assistantBlock]);

                // Продолжаем цикл — новый запрос
                continue;
            }

            // Если stopReason не pause_turn — выходим из цикла
            break;

        } while (true);

        // Формируем итоговый результат
        $result = new ClaudeResult(
            text: trim($allText),
            inputTokens: $totalInput,
            outputTokens: $totalOutput,
            cacheReadTokens: $totalCacheRead,
            cacheWriteTokens: $totalCacheWrite,
            webSearches: $totalSearches,
            stopReason: $finalStopReason,
            costUsd: $totalCost,
        );

        return $result;
    }


    /**
     * Серверные инструменты Anthropic: поиск и чтение страниц выполняются на
     * их стороне, своей инфраструктуры парсинга не нужно.
     *
     * @return list<mixed>
     */
    private function webTools(?string $geo): array
    {
        $tools = $this->config['tools'];

        $search = WebSearchTool20260209::with(
            maxUses: (int) $tools['max_searches'],
        );

        // Выдача зависит от страны. GEO у команды уже в двухбуквенном формате,
        // остаётся привести бытовые сокращения к ISO 3166-1 alpha-2.
        $country = self::toIsoCountry($geo);

        if ($country !== null) {
            $search = $search->withUserLocation(
                UserLocation::with(country: $country),
            );
        }

        return [
            $search,
            WebFetchTool20260209::with(
                maxUses: (int) $tools['max_fetches'],
            ),
        ];
    }

    /**
     * «UK» — обиходное сокращение команды, в ISO страна называется GB.
     * Остальное уже совпадает.
     */
    public static function toIsoCountry(?string $geo): ?string
    {
        $geo = strtoupper(trim((string) $geo));

        if (preg_match('/^[A-Z]{2}$/', $geo) !== 1) {
            return null;
        }

        return match ($geo) {
            'UK' => 'GB',
            'EL' => 'GR',
            default => $geo,
        };
    }



    private function toResult(Message $message): ClaudeResult
    {
        return self::parseMessageToResult($message, $this->config);
    }

    private function cost(int $in, int $out, int $cacheRead, int $cacheWrite): float
    {
        $p = $this->config['pricing'];

        return
            $in / 1_000_000 * (float) $p['input_per_mtok']
            + $out / 1_000_000 * (float) $p['output_per_mtok']
            + $cacheRead / 1_000_000 * (float) $p['cache_read_per_mtok']
            + $cacheWrite / 1_000_000 * (float) $p['cache_write_per_mtok'];
    }

    public static function parseMessageToResult(object $message, array $config): ClaudeResult
    {
        $text = '';
        foreach ($message->content as $block) {
            if (is_object($block) && property_exists($block, 'text')) {
                $text .= $block->text;
            }
        }
        $text = trim($text);

        $usage = $message->usage ?? (object) [];
        $cacheRead = $usage->cacheReadInputTokens ?? 0;
        $cacheWrite = $usage->cacheCreationInputTokens ?? 0;
        $searches = $usage->serverToolUse?->webSearchRequests ?? ($usage->webSearchRequests ?? 0);
        $inputTokens = (int) ($usage->inputTokens ?? 0);
        $outputTokens = (int) ($usage->outputTokens ?? 0);

        $toolErrors = [];
        $successfulToolResponses = 0;

        foreach ($message->content as $block) {
            if (!is_object($block)) {
                continue;
            }

            // Стандартный путь: блоки инструментов имеют поле 'tool'
            if (property_exists($block, 'tool')) {
                $toolName = (string) $block->tool;

                if (property_exists($block, 'error') && $block->error !== null) {
                    $err = $block->error;
                    $code = is_object($err) && property_exists($err, 'code') ? (string)$err->code : (string)$err;
                    $msg  = is_object($err) && property_exists($err, 'message') ? $err->message : null;
                    $toolErrors[] = ['tool' => $toolName, 'code' => $code, 'message' => $msg];
                }

                if (property_exists($block, 'results') && !empty((array)$block->results)) {
                    $successfulToolResponses += count((array)$block->results);
                } elseif (property_exists($block, 'items') && !empty((array)$block->items)) {
                    $successfulToolResponses += count((array)$block->items);
                } elseif (property_exists($block, 'url') || property_exists($block, 'title')) {
                    $successfulToolResponses += 1;
                }
            }

            // Альтернативные форматы: блоки с type, содержащим web/fetch
            if (property_exists($block, 'type') && is_string($block->type) && preg_match('/web|fetch/i', $block->type)) {
                if (property_exists($block, 'error') && $block->error !== null) {
                    $code = is_object($block->error) && property_exists($block->error, 'code') ? (string)$block->error->code : (string)$block->error;
                    $toolErrors[] = ['tool' => (string)$block->type, 'code' => $code];
                } else {
                    $successfulToolResponses += 1;
                }
            }
        }

        // Рассчитать стоимость (копия cost)
        $p = $config['pricing'] ?? [];
        $inputPer = (float) ($p['input_per_mtok'] ?? 0.0);
        $outputPer = (float) ($p['output_per_mtok'] ?? 0.0);
        $cacheReadPer = (float) ($p['cache_read_per_mtok'] ?? 0.0);
        $cacheWritePer = (float) ($p['cache_write_per_mtok'] ?? 0.0);

        $cost = $inputTokens / 1_000_000 * $inputPer
            + $outputTokens / 1_000_000 * $outputPer
            + $cacheRead / 1_000_000 * $cacheReadPer
            + $cacheWrite / 1_000_000 * $cacheWritePer;

        return new ClaudeResult(
            text: $text,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheReadTokens: (int)$cacheRead,
            cacheWriteTokens: (int)$cacheWrite,
            webSearches: (int)$searches,
            stopReason: $message->stopReason ?? null,
            costUsd: $cost,
            toolErrors: $toolErrors,
            successfulToolResponses: $successfulToolResponses,
        );
    }

}
