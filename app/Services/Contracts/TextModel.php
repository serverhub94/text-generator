<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Services\ClaudeResult;

/**
 * Модель, которую гоняет конвейер.
 *
 * Интерфейс существует ради тестов: он позволяет прогнать все стадии, циклы
 * переписывания и учёт расходов, не тратя денег на реальные запросы.
 */
interface TextModel
{
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
    ): ClaudeResult;
}
