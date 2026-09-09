<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Run;
use Illuminate\Console\Command;

/**
 * Истории в интерфейсе нет — прогоны живут ровно столько, сколько нужно,
 * чтобы человек забрал результат по ссылке.
 */
class PruneRuns extends Command
{
    protected $signature = 'textgen:prune';

    protected $description = 'Удаляет прогоны старше TEXTGEN_PRUNE_AFTER_DAYS';

    public function handle0(): int
    {
        $days = (int) config('textgen.limits.prune_after_days');

        if ($days <= 0) {
            $this->info('Очистка отключена (prune_after_days = 0).');

            return self::SUCCESS;
        }

        $deleted = Run::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Удалено прогонов: {$deleted}.");

        return self::SUCCESS;
    }

    public function handle(): int
    {
        // CHANGES: используем значение из конфига, fallback = 90 дней
        $days = (int) config('textgen.limits.prune_after_days', 90);

        if ($days <= 0) {
            $this->info('Очистка отключена (prune_after_days = 0).');

            return self::SUCCESS;
        }

        $this->info("Запускаю очистку прогонов старше {$days} дней...");

        $deleted = Run::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Удалено прогонов: {$deleted}.");

        return self::SUCCESS;
    }
}
