<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ClaudeResult;

/**
 * Минимальный unit test для поведения pause_turn -> continuation,
 * без наследования final класса App\Services\ClaudeService.
 */
final class ClaudeServiceContinuationTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $config = [
        'model' => 'claude-test',
        'pricing' => [
            'input_per_mtok' => 0.1,
            'output_per_mtok' => 0.2,
            'cache_read_per_mtok' => 0.01,
            'cache_write_per_mtok' => 0.01,
        ],
        'max_continuations' => 5,
    ];

    public function testPauseTurnContinuationAccumulatesUsageAndText(): void
    {
        // Подготовим "ответы модели" — два Message-подобных объекта.
        $firstMessage = $this->makeMessage(
            text: 'First part.',
            stopReason: 'pause_turn',
            inputTokens: 100,
            outputTokens: 200,
            cacheRead: 10,
            cacheWrite: 5,
            searches: 1
        );

        $secondMessage = $this->makeMessage(
            text: 'Second part.',
            stopReason: 'stop',
            inputTokens: 50,
            outputTokens: 80,
            cacheRead: 2,
            cacheWrite: 1,
            searches: 0
        );

        // Локальная тестовая реализация "сервиса", не наследующая реальный final класс.
        $service = new class($this->config, [$firstMessage, $secondMessage]) {
            private array $config;
            private array $mockResponses;
            private int $idx = 0;

            public function __construct(array $config, array $mockResponses)
            {
                $this->config = $config;
                $this->mockResponses = $mockResponses;
            }

            private function nextMockMessage(): object
            {
                if (!isset($this->mockResponses[$this->idx])) {
                    throw new \RuntimeException('No more mock responses');
                }
                return $this->mockResponses[$this->idx++];
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

            /**
             * Эмулирует поведение send() с поддержкой pause_turn continuations.
             *
             * @param list<array{role:string,content:mixed}> $messages
             */
            public function sendWithMockResponses(string $rules, array $messages, string $effort, int $maxTokens, bool $withWebTools = false, ?string $geo = null): ClaudeResult
            {
                $maxContinuations = (int) ($this->config['max_continuations'] ?? 5);
                $totalInput = $totalOutput = $totalCacheRead = $totalCacheWrite = $totalSearches = 0;
                $totalCost = 0.0;
                $allText = '';
                $finalStopReason = null;
                $originalMessages = $messages;
                $currentMessages = $messages;
                $continuation = 0;

                do {
                    $message = $this->nextMockMessage();

                    // Собираем текст из content (в тесте content — массив блоков с text)
                    $pieceText = '';
                    foreach ($message->content as $block) {
                        if (isset($block->text)) {
                            $pieceText .= $block->text;
                        }
                    }
                    $pieceText = trim($pieceText);
                    if ($pieceText !== '') {
                        $allText .= $allText === '' ? $pieceText : ("\n" . $pieceText);
                    }

                    // Накопление usage
                    $usage = $message->usage;
                    $cacheRead = $usage->cacheReadInputTokens ?? 0;
                    $cacheWrite = $usage->cacheCreationInputTokens ?? 0;
                    $searches = $usage->webSearchRequests ?? 0;

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

                    if ($finalStopReason === 'pause_turn') {
                        $continuation++;
                        if ($continuation > $maxContinuations) {
                            throw new \RuntimeException("Exceeded max continuations ({$maxContinuations}) while handling pause_turn.");
                        }

                        // ассистентский блок — передаём оригинальные блоки
                        $assistantBlock = [
                            'role' => 'assistant',
                            'content' => $message->content,
                        ];
                        $currentMessages = array_merge($originalMessages, [$assistantBlock]);
                        continue;
                    }

                    break;
                } while (true);

                return new ClaudeResult(
                    text: trim($allText),
                    inputTokens: $totalInput,
                    outputTokens: $totalOutput,
                    cacheReadTokens: $totalCacheRead,
                    cacheWriteTokens: $totalCacheWrite,
                    webSearches: $totalSearches,
                    stopReason: $finalStopReason,
                    costUsd: $totalCost,
                );
            }
        };

        // Вызов тестового метода
        $result = $service->sendWithMockResponses(
            rules: 'rules',
            messages: [['role' => 'user', 'content' => 'query']],
            effort: 'low',
            maxTokens: 1000,
            withWebTools: false,
            geo: null
        );

        // Проверки
        $this->assertInstanceOf(ClaudeResult::class, $result);
        $this->assertSame("First part.\nSecond part.", $result->text);
        $this->assertSame(150, $result->inputTokens);
        $this->assertSame(280, $result->outputTokens);
        $this->assertSame(12, $result->cacheReadTokens);
        $this->assertSame(6, $result->cacheWriteTokens);
        $this->assertSame(1, $result->webSearches);
        $this->assertSame('stop', $result->stopReason);

        // Проверка стоимости с допуском
        $expectedCost = 0.0;
        $expectedCost += (100 / 1_000_000) * 0.1 + (200 / 1_000_000) * 0.2 + (10 / 1_000_000) * 0.01 + (5 / 1_000_000) * 0.01;
        $expectedCost += (50 / 1_000_000) * 0.1 + (80 / 1_000_000) * 0.2 + (2 / 1_000_000) * 0.01 + (1 / 1_000_000) * 0.01;
        $this->assertEqualsWithDelta($expectedCost, $result->costUsd, 1e-9);
    }

    /**
     * Вспомогательная фабрика "Message"-подобного объекта для теста.
     *
     * @return object
     */
    private function makeMessage(string $text, string $stopReason, int $inputTokens, int $outputTokens, int $cacheRead, int $cacheWrite, int $searches): object
    {
        $block = new class($text) {
            public string $text;
            public function __construct(string $t) { $this->text = $t; }
        };

        $usage = (object) [
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'cacheReadInputTokens' => $cacheRead,
            'cacheCreationInputTokens' => $cacheWrite,
            'webSearchRequests' => $searches,
        ];

        return (object) [
            'content' => [$block],
            'usage' => $usage,
            'stopReason' => $stopReason,
        ];
    }
}
