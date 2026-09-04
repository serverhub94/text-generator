<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Результат одного обращения к модели: текст плюс всё, что нужно для учёта
 * расходов и диагностики.
 */
final readonly class ClaudeResult
{
    public function __construct(
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        public int $webSearches,
        public ?string $stopReason,
        public float $costUsd,
    ) {}

    /**
     * Модель отказалась отвечать. На гэмблинг-тематике это возможно, поэтому
     * стадии обязаны проверять этот флаг, а не молча писать пустой результат.
     */
    public function isRefusal(): bool
    {
        return $this->stopReason === 'refusal';
    }

    /**
     * Ответ упёрся в потолок токенов и оборван на полуслове.
     */
    public function isTruncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }
}
