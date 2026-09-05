<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Run;
use App\Services\Pipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Гоняет конвейер в очереди.
 *
 * Полный цикл на V5/V7 идёт минутами: держать всё это время открытым HTTP-
 * соединение через nginx и php-fpm — верный способ ловить 504. Поэтому веб
 * только ставит задачу, а браузер опрашивает статус.
 */
class RunPipeline implements ShouldQueue
{
    use Queueable;

    /**
     * Стадии долгие, а на V7 их пять плюс переписывания. Час — потолок
     * с запасом; если упёрлись в него, что-то не так, и лучше упасть.
     *
     * ВАЖНО: значение должно быть строго меньше конфигурационного
     * retry_after для драйвера database (DB_QUEUE_RETRY_AFTER).
     * По умолчанию $timeout = 3600, поэтому DB_QUEUE_RETRY_AFTER должен быть > 3600.
     */
    public int $timeout = 3600;

    /**
     * Повторов нет: каждая попытка стоит денег, а падение почти всегда
     * означает проблему во вводных, а не сетевой сбой.
     */
    public int $tries = 1;

    public function __construct(public readonly int $runId) {}

    public function handle(Pipeline $pipeline): void
    {
        $run = Run::find($this->runId);

        if ($run === null) {
            return;
        }

        $run->update([
            'status' => Run::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $result = $pipeline
            ->onStage(function (string $stage) use ($run) {
                // Пишем текущую стадию сразу — это единственный признак
                // прогресса, который видит человек в браузере.
                $run->forceFill(['stage' => $stage])->save();
            })
            ->run($run->input);

        $run->update([
            'status' => Run::STATUS_DONE,
            'stage' => null,
            'stages' => $result['stages'],
            'article' => $result['article'],
            'article_meta' => $result['article_meta'],
            'usage' => $result['usage'],
            'cost_usd' => $result['cost'],
            'finished_at' => now(),
        ]);
    }

    public function failed(?Throwable $e): void
    {
        Run::where('id', $this->runId)->update([
            'status' => Run::STATUS_FAILED,
            'stage' => null,
            'error' => $e?->getMessage() ?? 'Неизвестная ошибка.',
            'finished_at' => now(),
        ]);
    }
}
