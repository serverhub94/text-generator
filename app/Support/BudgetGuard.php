<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Run;
use Carbon\Carbon;
/**
 * Потолок расходов на API за календарный месяц.
 *
 * Форма открыта без авторизации, поэтому единственное, что стоит между ключом
 * и случайным гостем, — лимиты. Считаем по фактически израсходованным токенам
 * прошедших прогонов: это не биллинг Anthropic, а близкая оценка, но её
 * достаточно, чтобы поймать разгон до того, как он станет дорогим.
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
     * Суммируем по каждому прогону ориентировочную стоимость, вычисленную из
     * estimated_tokens в конфиге режимов и цен в config('textgen.pricing').
     *
     * Результат мемоизируется на время жизни экземпляра.
     */
    public function reserved(): float
    {
        if ($this->reservedCache !== null) {
            return $this->reservedCache;
        }

        $modes = config('textgen.modes', []);
        $pricing = config('textgen.pricing', [
            'input_per_mtok' => 0.0,
            'output_per_mtok' => 0.0,
            'cache_read_per_mtok' => 0.0,
            'cache_write_per_mtok' => 0.0,
        ]);

        $inputPerMtok = (float) ($pricing['input_per_mtok'] ?? 0.0);
        $outputPerMtok = (float) ($pricing['output_per_mtok'] ?? 0.0);

        // Берём прогоны в статусах queued и running
        $runs = Run::query()
            ->whereIn('status', [Run::STATUS_QUEUED, Run::STATUS_RUNNING])
            ->get(['mode', 'input']);

        $sum = 0.0;

        foreach ($runs as $run) {
            // Определяем режим: сначала поле mode, иначе из input['mode']
            $modeKey = $run->mode ?? ($run->input['mode'] ?? null);
            if ($modeKey === null) {
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

            $inTokens = (int) ($est['input'] ?? 0);
            $outTokens = (int) ($est['output'] ?? 0);

            // Переводим токены в USD
            $cost = ($inTokens / 1_000_000.0) * $inputPerMtok
                + ($outTokens / 1_000_000.0) * $outputPerMtok;

            $sum += $cost;
        }

        $this->reservedCache = $sum;

        return $this->reservedCache;
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
