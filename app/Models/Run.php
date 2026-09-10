<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Один прогон конвейера.
 *
 * CHANGES (в этой версии):
 * - Перенёс касты в свойство $casts и добавил 'warnings'.
 * - Добавил явный список $fillable (включая model, target_query, session_id).
 * - Добавил booted() для гарантированной генерации uuid при создании записи.
 * - Добавил scopeVisibleForHistory() для разграничения видимости истории по config('textgen.history_scope').
 * - Добавил isOwnedBySession() для проверки права удаления прогона.
 * - Сохранил HasUuids и getRouteKeyName() для маршрутизации по uuid.
 *
 * Примечание: миграция должна добавить колонки target_query (varchar 200), session_id (string 64) и индекс на created_at.
 */
class Run extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    /**
     * CHANGES: явный список полей, разрешённых для массового присвоения.
     * Включены новые поля: model, target_query, session_id, warnings и т.д.
     */
    protected $fillable = [
        'status',
        'mode',
        'input',
        'ip',
        'model',         // CHANGES: сохранённая модель
        'target_query',  // CHANGES: денормализованный целевой запрос
        'session_id',    // CHANGES: идентификатор сессии
        'stage',         // текущая стадия (ед. ч.) — колонка есть в миграции
        'stages',
        'usage',
        'cost_usd',
        'article',
        'article_meta',
        'warnings',
        'started_at',
        'finished_at',
        'error',
        'uuid',
    ];

    /**
     * CHANGES: привёл к стандартному свойству $casts (вместо метода).
     * Добавлены: warnings; cost_usd как float; даты как datetime.
     */
    protected $casts = [
        'input' => 'array',
        'stages' => 'array',
        'article_meta' => 'array',
        'usage' => 'array',
        'warnings' => 'array',
        'cost_usd' => 'float',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * HasUuids по умолчанию заполняет первичный ключ — нам нужен отдельный столбец uuid,
     * id остаётся инкрементным. uniqueIds() оставлен для совместимости с вашим кодом.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Маршрутизация по uuid вместо id.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * CHANGES: при создании прогона гарантируем наличие uuid (если по какой-то причине не сгенерировался).
     */
    protected static function booted(): void
    {
        static::creating(function (self $run) {
            if (empty($run->uuid)) {
                $run->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Проверка, завершён ли прогон.
     */
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

    /**
     * Scope: видимость записей истории в зависимости от config('textgen.history_scope').
     *
     * - Если history_scope = 'session' — показываем только записи текущей сессии.
     * - Если history_scope = 'all' — показываем все записи.
     *
     * Пример использования: Run::visibleForHistory()->paginate(25);
     *
     * CHANGES: добавлен scopeVisibleForHistory для удобства контроллера/репортов.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $sessionId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeVisibleForHistory($query, ?string $sessionId = null)
    {
        $scope = config('textgen.history_scope', 'session');
        if ($scope === 'session') {
            $sessionId = $sessionId ?? session()->getId();
            return $query->where('session_id', $sessionId);
        }
        return $query;
    }

    /**
     * Проверка принадлежности прогона сессии (для удаления).
     *
     * CHANGES: используется в контроллере при удалении (history_scope = 'session').
     *
     * @param string|null $sessionId
     * @return bool
     */
    public function isOwnedBySession(?string $sessionId): bool
    {
        if ($sessionId === null) {
            return false;
        }
        return (string) $this->session_id === (string) $sessionId;
    }
}
