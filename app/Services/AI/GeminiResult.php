<?php
declare(strict_types=1);

namespace App\Services\AI;

final readonly class GeminiResult
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

    public function isRefusal(): bool
    {
        return $this->stopReason === 'refusal';
    }

    public function isTruncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }
}
