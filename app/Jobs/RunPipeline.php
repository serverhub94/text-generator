<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Run;
use App\Services\Pipeline;
//use Illuminate\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Гоняет конвейер в очереди.
 *
 * Изменения:
 * - Подписываемся на onStageDone и сохраняем промежуточные результаты в БД
 *   после каждой стадии (stages, usage, cost_usd).
 * - Финальное обновление сохраняет warnings, если они есть.
 */
class RunPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * Стадии долгие, а на V7 их пять плюс переписывания. Час — потолок
     * с запасом; если упёрлись в него, что-то не так, и лучше упасть.
     */
    public int $timeout = 3600;

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

        // Подписываемся на onStage — для отображения текущей стадии в UI
        $pipeline = $pipeline->onStage(function (string $stage) use ($run) {
            $run->forceFill(['stage' => $stage])->save();
        });

        // Подписываемся на onStageDone — сохраняем промежуточные результаты в БД
        $pipeline = $pipeline->onStageDone(function (string $stage, string $text, array $usage, float $cost) use ($run) {
            $stages = (array) $run->stages;
            $stages[$stage] = $text;

            // Сохраняем промежуточные данные: stages, usage, cost_usd
            $run->forceFill([
                'stages' => $stages,
                'usage' => $usage,
                'cost_usd' => $cost,
            ])->save();
        });

        // Запускаем pipeline — он вернёт текущие накопленные данные (включая warnings)
        $result = $pipeline->run($run->input);

        // Обновляем финальные поля (включая warnings, если есть)
        $run->update([
            'status' => Run::STATUS_DONE,
            'stage' => null,
            'stages' => $result['stages'],
            'article' => $result['article'],
            'article_meta' => $result['article_meta'],
            'usage' => $result['usage'],
            'cost_usd' => $result['cost'],
            'warnings' => $result['warnings'] ?? null,
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
