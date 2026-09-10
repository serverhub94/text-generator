<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\ClaudeResult;
use App\Services\Contracts\TextModel;
use App\Services\ModelProfile;

/**
 * Подставная модель: записывает всё, что ей передали, и отдаёт заранее
 * заготовленные ответы. Позволяет проверить порядок стадий, чередование
 * ролей и цикл переписывания, не потратив ни цента.
 */
final class FakeTextModel implements TextModel
{
    /** @var list<array{rules: string, messages: array, effort: string, maxTokens: int, webTools: bool, geo: ?string}> */
    public array $calls = [];

    /** @param list<string> $responses */
    public function __construct(private array $responses = []) {}

    public function send(
        string $rules,
        array $messages,
        string $effort,
        int $maxTokens,
        bool $withWebTools = false,
        ?string $geo = null,
        ?ModelProfile $profile = null,
    ): ClaudeResult {
        $this->calls[] = [
            'rules' => $rules,
            'messages' => $messages,
            'effort' => $effort,
            'maxTokens' => $maxTokens,
            'webTools' => $withWebTools,
            'geo' => $geo,
            'profile' => $profile,
        ];

        $text = array_shift($this->responses) ?? 'Ответ по умолчанию.';

        return new ClaudeResult(
            text: $text,
            inputTokens: 1000,
            outputTokens: 500,
            cacheReadTokens: 200,
            cacheWriteTokens: 0,
            webSearches: $withWebTools ? 3 : 0,
            stopReason: 'end_turn',
            costUsd: 0.0175,
            successfulToolResponses: $withWebTools ? 3 : 0,
        );
    }

    /** Задание последней стадии — последнее сообщение пользователя. */
    public function lastUserContent(): string
    {
        $messages = end($this->calls)['messages'];

        return (string) end($messages)['content'];
    }

    /** @return list<string> */
    public function roleSequenceOfLastCall(): array
    {
        return array_column(end($this->calls)['messages'], 'role');
    }
}
