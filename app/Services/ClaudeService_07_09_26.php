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
    public function send(
        string $rules,
        array $messages,
        string $effort,
        int $maxTokens,
        bool $withWebTools = false,
        ?string $geo = null,
    ): ClaudeResult {
        $stream = $this->client->messages->createStream(
            maxTokens: $maxTokens,
            messages: $messages,
            model: (string) $this->config['model'],
            // Кешируем растущий префикс переписки: SDK ставит точку разрыва
            // на последний кешируемый блок, поэтому каждая следующая стадия
            // читает предыдущие из кеша вместо повторной оплаты.
            cacheControl: ['type' => 'ephemeral', 'ttl' => '1h'],
            outputConfig: ['effort' => $effort],
            // Правила неизменны в пределах рынка — это стабильный префикс.
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

        return $this->toResult($message);
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     */



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
        $text = '';

        foreach ($message->content as $block) {
            // Блоки полиморфны: помимо текста приходят thinking и результаты
            // серверных инструментов. Забираем только текстовые.
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        $usage = $message->usage;
        $cacheRead = $usage->cacheReadInputTokens ?? 0;
        $cacheWrite = $usage->cacheCreationInputTokens ?? 0;
        $searches = $usage->serverToolUse?->webSearchRequests ?? 0;

        return new ClaudeResult(
            text: trim($text),
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheReadTokens: $cacheRead,
            cacheWriteTokens: $cacheWrite,
            webSearches: $searches,
            stopReason: $message->stopReason,
            costUsd: $this->cost(
                $usage->inputTokens,
                $usage->outputTokens,
                $cacheRead,
                $cacheWrite,
            ),
        );
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
}
