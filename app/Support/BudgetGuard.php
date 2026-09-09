<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Run;
use Carbon\Carbon;
use App\Services\ModelProfile;
use RuntimeException;

/**
 * Потолок расходов на API за календарный месяц.
 *
 * Описание изменений:
 * - Добавлен статический метод estimateCostForMode(array $mode, ModelProfile $profile)
 *   для расчёта стоимости одного режима по тарифам модели.
 * - Метод reserved() теперь использует estimated_tokens режима и тарифы выбранной
 *   модели (ModelProfile::pricing() / ModelProfile::cost()).
 * - Для каждого прогона пытаемся определить модель (runs.model -> input['model'] ->
 *   config default -> первая enabled) и создать ModelProfile.
 * - Если профиль модели получить не удалось, используем fallback тарифы из
 *   config('textgen.pricing') (старый формат per_mtok).
 * - Все изменения подписаны комментариями на русском.
 */
final class BudgetGuard
{
    private ?float $spentCache = null;
    private ?float $reservedCache = null;

    public function __construct(private readonly float $monthlyBudgetUsd) {}

    public function isDisabled(): bool
    {
        return $this->monthlyBudgetUsd <= 0.0;
    }

    /**
     * Сумма реально потраченного за месяц (по завершённым прогонам).
     * Результат мемоизируется на время жизни экземпляра.
     */
    public function spentThisMonth(): float
    {
        if ($this->spentCache !== null) {
            return $this->spentCache;
        }

        $this->spentCache = (float) Run::query()
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('cost_usd');

        return $this->spentCache;
    }

    /**
     * Оценка зарезервированного бюджета для прогонов в очереди и выполняющихся.
     *
     * Логика:
     * - Берём прогоны в статусах queued и running.
     * - Для каждого прогона определяем режим и модель.
     * - Если модель найдена и профиль создан — используем ModelProfile::cost
     *   через вспомогательный метод estimateCostForMode.
     * - Если профиль не получен — используем fallback тарифы из config('textgen.pricing').
     *
     * Результат мемоизируется на время жизни экземпляра.
     */
    public function reserved(): float
    {
        if ($this->reservedCache !== null) {
            return $this->reservedCache;
        }

        $modes = config('textgen.modes', []);
        $fallbackPricing = config('textgen.pricing', [
            'input_per_mtok' => 0.0,
            'output_per_mtok' => 0.0,
            'cache_read_per_mtok' => 0.0,
            'cache_write_per_mtok' => 0.0,
        ]);

        // Берём прогоны в статусах queued и running
        $runs = Run::query()
            ->whereIn('status', [Run::STATUS_QUEUED, Run::STATUS_RUNNING])
            ->get(['mode', 'input', 'model']);

        $sum = 0.0;

        foreach ($runs as $run) {
            // 1) Определяем ключ модели для прогона:
            //    приоритет: поле runs.model -> input['model'] -> config default -> первая enabled
            $modelKey = $run->model ?? ($run->input['model'] ?? null);

            if ($modelKey === null) {
                $modelKey = config('textgen.default_model') ?? null;
            }

            if ($modelKey === null) {
                $modelsCfg = config('textgen.models', []);
                foreach ($modelsCfg as $k => $m) {
                    if (!empty($m['default'])) {
                        $modelKey = $k;
                        break;
                    }
                }
            }

            if ($modelKey === null) {
                $modelsCfg = config('textgen.models', []);
                foreach ($modelsCfg as $k => $m) {
                    if (!empty($m['enabled'])) {
                        $modelKey = $k;
                        break;
                    }
                }
            }

            // 2) Получаем конфигурацию режима (estimated_tokens)
            $modeKey = $run->mode ?? ($run->input['mode'] ?? null);
            if ($modeKey === null) {
                // не можем оценить — пропускаем
                continue;
            }

            $modeCfg = $modes[$modeKey] ?? null;
            if (!is_array($modeCfg)) {
                continue;
            }

            $est = $modeCfg['estimated_tokens'] ?? null;
            if (!is_array($est)) {
                continue;
            }

            // 3) Если есть модель — пробуем создать профиль и посчитать стоимость через него
            $costForRun = 0.0;
            if ($modelKey !== null) {
                try {
                    $profile = ModelProfile::fromConfig($modelKey, config('textgen'));
                    // CHANGES: используем вспомогательный метод estimateCostForMode
                    $costForRun = self::estimateCostForMode($modeCfg, $profile);
                } catch (\Throwable $e) {
                    // Если профиль не найден или ошибка — fallback на глобальные тарифы
                    // (не прерываем выполнение; можно логировать $e при необходимости)
                    $costForRun = $this->costUsingFallbackRates($est, $fallbackPricing);
                }
            } else {
                // Нет модели — используем fallback
                $costForRun = $this->costUsingFallbackRates($est, $fallbackPricing);
            }

            $sum += $costForRun;
        }

        $this->reservedCache = $sum;

        return $this->reservedCache;
    }

    /**
     * Вспомогательный публичный метод.
     *
     * Рассчитать стоимость режима по тарифам модели.
     *
     * @param array<string,mixed> $modeCfg  Конфигурация режима (из config('textgen.modes')[key])
     * @param ModelProfile $profile        Профиль модели с тарифами
     *
     * Возвращает стоимость в USD (float).
     *
     * CHANGES:
     * - Метод использует estimated_tokens из $modeCfg и вызывает $profile->cost(...)
     * - Это делает логику расчёта переиспользуемой и легко тестируемой.
     */
    public static function estimateCostForMode(array $modeCfg, ModelProfile $profile): float
    {
        $est = $modeCfg['estimated_tokens'] ?? ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];

        $in = (int) ($est['input'] ?? 0);
        $out = (int) ($est['output'] ?? 0);
        $cacheRead = (int) ($est['cache_read'] ?? 0);
        $cacheWrite = (int) ($est['cache_write'] ?? 0);

        // ModelProfile::cost ожидает целые токены и возвращает float USD
        return $profile->cost($in, $out, $cacheRead, $cacheWrite);
    }

    /**
     * Вспомогательный метод: расчёт стоимости по fallback тарифам (старый формат *_per_mtok).
     *
     * @param array<string,int> $est  estimated_tokens
     * @param array<string,mixed> $fallbackPricing  config('textgen.pricing')
     */
    private function costUsingFallbackRates(array $est, array $fallbackPricing): float
    {
        $inTokens = (int) ($est['input'] ?? 0);
        $outTokens = (int) ($est['output'] ?? 0);
        $cacheRead = (int) ($est['cache_read'] ?? 0);
        $cacheWrite = (int) ($est['cache_write'] ?? 0);

        $inputPerMtok = (float) ($fallbackPricing['input_per_mtok'] ?? 0.0);
        $outputPerMtok = (float) ($fallbackPricing['output_per_mtok'] ?? 0.0);
        $cacheReadPerMtok = (float) ($fallbackPricing['cache_read_per_mtok'] ?? 0.0);
        $cacheWritePerMtok = (float) ($fallbackPricing['cache_write_per_mtok'] ?? 0.0);

        $inRate = $inputPerMtok / 1_000_000.0;
        $outRate = $outputPerMtok / 1_000_000.0;
        $crRate = $cacheReadPerMtok / 1_000_000.0;
        $cwRate = $cacheWritePerMtok / 1_000_000.0;

        return $inTokens * $inRate
            + $outTokens * $outRate
            + $cacheRead * $crRate
            + $cacheWrite * $cwRate;
    }

    public function remaining(): float
    {
        return max(0.0, $this->monthlyBudgetUsd - $this->spentThisMonth());
    }

    public function exceeded(): bool
    {
        if ($this->isDisabled()) {
            return false;
        }

        // Учитываем резерв
        return ($this->spentThisMonth() + $this->reserved()) >= $this->monthlyBudgetUsd;
    }

    /**
     * Доля израсходованного, 0..1 — для полоски в интерфейсе.
     * Показываем отношение (spent + reserved) / budget, но не больше 1.
     */
    public function fraction(): float
    {
        if ($this->isDisabled()) {
            return 0.0;
        }

        $used = $this->spentThisMonth() + $this->reserved();

        return min(1.0, $used / $this->monthlyBudgetUsd);
    }

    public function budget(): float
    {
        return $this->monthlyBudgetUsd;
    }
}
