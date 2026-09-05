<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Один прогон конвейера.
 *
 * Истории в интерфейсе нет — прогоны хранятся только потому, что генерация
 * идёт в очереди и браузеру нужно куда-то опрашивать статус. Старые записи
 * подчищает `textgen:prune`.
 */
class Run extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    /**
     * HasUuids по умолчанию заполняет первичный ключ — нам нужен только
     * отдельный столбец uuid, id остаётся инкрементным.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'stages' => 'array',
            'article_meta' => 'array',
            'usage' => 'array',
            'cost_usd' => 'float',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }

    /**
     * Выход конкретной стадии, если она отработала.
     */
    public function stageOutput(string $stage): ?string
    {
        return $this->stages[$stage] ?? null;
    }

    /**
     * On-page поле статьи: title, description, url или служебные заметки.
     */
    public function articleMeta(string $key): string
    {
        return trim((string) ($this->article_meta[$key] ?? ''));
    }
}
