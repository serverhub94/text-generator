<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Run;
use Illuminate\Support\Carbon;

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
    public function __construct(private readonly float $monthlyBudgetUsd) {}

    public function isDisabled(): bool
    {
        return $this->monthlyBudgetUsd <= 0.0;
    }

    public function spentThisMonth(): float
    {
        return (float) Run::query()
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('cost_usd');
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

        return $this->spentThisMonth() >= $this->monthlyBudgetUsd;
    }

    /**
     * Доля израсходованного, 0..1 — для полоски в интерфейсе.
     */
    public function fraction(): float
    {
        if ($this->isDisabled()) {
            return 0.0;
        }

        return min(1.0, $this->spentThisMonth() / $this->monthlyBudgetUsd);
    }

    public function budget(): float
    {
        return $this->monthlyBudgetUsd;
    }
}
