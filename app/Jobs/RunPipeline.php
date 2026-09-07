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
use Illuminate\Support\Facades\Log;
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

    public function handle0(Pipeline $pipeline): void
    {
        $run = Run::find($this->runId);

        if ($run === null) {
            return;
        }

        // Если в input указана модель — создаём Pipeline с нужным клиентом
        $aiModel = $run->input['ai_model'] ?? null;

        if ($aiModel !== null && strtolower($aiModel) !== 'claude') {
            $factory = app(\App\Services\AI\AiClientFactory::class);
            $client = $factory->make($aiModel);

            // Создаём Pipeline вручную с теми же зависимостями, что и контейнерный
            $prompts = app(\App\Services\PromptRepository::class);
            $config = config('textgen');

            $pipeline = new \App\Services\Pipeline($client, $prompts, $config);
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
    public function handle1(): void
    {
        $run = Run::find($this->runId);
        if ($run === null) return;

        // Определяем модель: из input или дефолт
        $aiModel = strtolower((string) ($run->input['ai_model'] ?? config('textgen.default_ai', 'claude')));

        // Получаем фабрику и создаём клиент
        $factory = app(\App\Services\AI\AiClientFactory::class);

        try {
            $client = $factory->make($aiModel);
        } catch (\Throwable $e) {
            $run->update([
                'status' => Run::STATUS_FAILED,
                'stage' => null,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            return;
        }

        // Создаём Pipeline вручную
        $prompts = app(\App\Services\PromptRepository::class);
        $pipeline = new \App\Services\Pipeline($client, $prompts, config('textgen'));

        // Дальше — подписки и запуск как было
        $run->update(['status' => Run::STATUS_RUNNING, 'started_at' => now()]);

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
                'cost_usd' => $cost
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

    public function handle2(): void
    {
        $run = Run::find($this->runId);

        if ($run === null) {
            return;
        }

        // Определяем модель: из input или дефолт из конфига
        $aiModel = strtolower((string) ($run->input['ai_model'] ?? config('textgen.default_ai', 'claude')));

        // Попытка получить клиент через фабрику
        try {
            $factory = app(\App\Services\AI\AiClientFactory::class);
            $client = $factory->make($aiModel);
        } catch (\Throwable $e) {
            // Сохраняем понятную ошибку и помечаем Run как failed
            $run->update([
                'status' => Run::STATUS_FAILED,
                'stage' => null,
                'error' => 'AI provider error: ' . $e->getMessage(),
                'finished_at' => now(),
            ]);

            // Логируем для отладки
            \Log::error('RunPipeline: failed to resolve AI client', [
                'run_id' => $this->runId,
                'ai_model' => $aiModel,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        // Создаём Pipeline вручную с выбранным клиентом
        $prompts = app(\App\Services\PromptRepository::class);
        $pipeline = new \App\Services\Pipeline($client, $prompts, config('textgen'));

        // Обновляем статус и время старта
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

        // Запуск pipeline — оборачиваем в try/catch, чтобы при ошибке пометить Run как failed
        //  он вернёт текущие накопленные данные (включая warnings)
        try {
            $result = $pipeline->run($run->input);
        } catch (\Throwable $e) {
            $run->update([
                'status' => Run::STATUS_FAILED,
                'stage' => null,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            \Log::error('RunPipeline: pipeline execution failed', [
                'run_id' => $this->runId,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

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

    public function handle(): void
    {
        $run = Run::find($this->runId);

        if ($run === null) {
            return;
        }

        $aiModel = strtolower((string) ($run->input['ai_model'] ?? config('textgen.default_ai', 'claude')));

        try {
            $factory = app(\App\Services\AI\AiClientFactory::class);
            $client = $factory->make($aiModel);
        } catch (\Throwable $e) {
            // Сохраняем понятную ошибку в базе
            $run->update([
                'status' => Run::STATUS_FAILED,
                'stage' => null,
                'error' => 'AI provider error: ' . $e->getMessage(),
                'finished_at' => now(),
            ]);

            Log::error('RunPipeline: failed to resolve AI client', [
                'run_id' => $this->runId,
                'ai_model' => $aiModel,
                'exception' => $e->getMessage(),
            ]);

            // Бросаем исключение дальше, чтобы воркер пометил job как failed
            throw $e;
        }

        $prompts = app(\App\Services\PromptRepository::class);

       // $pipeline = new \App\Services\Pipeline($client, $prompts, config('textgen'));
        // после $client и $prompts
        if ($aiModel === 'gemini') {
            // $client уже инстанцирован фабрикой как GeminiService

            $pipeline = new \App\Services\AI\GeminiPipeline($client, $prompts, config('textgen'), app(\Psr\Log\LoggerInterface::class));
        } else {

            $pipeline = new \App\Services\Pipeline($client, $prompts, config('textgen'));
        }

        $run->update([
            'status' => Run::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $pipeline = $pipeline->onStage(function (string $stage) use ($run) {
            $run->forceFill(['stage' => $stage])->save();
        });

        $pipeline = $pipeline->onStageDone(function (string $stage, string $text, array $usage, float $cost) use ($run) {
            $stages = (array) $run->stages;
            $stages[$stage] = $text;

            $run->forceFill([
                'stages' => $stages,
                'usage' => $usage,
                'cost_usd' => $cost,
            ])->save();
        });

        try {
            $result = $pipeline->run($run->input);
        } catch (\Throwable $e) {
            // Сохраняем ошибку и помечаем Run как failed
            $run->update([
                'status' => Run::STATUS_FAILED,
                'stage' => null,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            \Log::error('RunPipeline: pipeline execution failed', [
                'run_id' => $this->runId,
                'exception' => $e->getMessage(),
            ]);

            // Бросаем исключение дальше, чтобы воркер корректно обработал падение
            throw $e;
        }

        // Финальное обновление (как было)
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
