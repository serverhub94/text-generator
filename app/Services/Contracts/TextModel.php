<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Services\ClaudeResult;
use App\Services\ModelProfile; // <-- NEW: импорт профиля модели

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
     *
     * CHANGES:
     * - Added optional ModelProfile $profile parameter to allow implementations
     *   to adapt request options (thinking, output_config, tools) based on model capabilities.
     */
    public function send(
        string $rules,
        array $messages,
        string $effort,
        int $maxTokens,
        bool $withWebTools = false,
        ?string $geo = null,
        ?ModelProfile $profile = null, // <-- NEW: профиль модели, опционально
    ): ClaudeResult;
}
