<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Support\BudgetGuard;
use App\Models\Run;

final class BudgetGuardReservedTest extends TestCase
{
    use RefreshDatabase;

    public function testReservedAndExceededConsiderQueuedAndRunningRuns(): void
    {
        // Подготовим конфиг: простая цена и режимы с estimated_tokens.
        // Реестр моделей и default_model очищаем — прогоны ниже создаются без
        // модели, и тест намеренно проверяет ветку fallback-тарифов из pricing.
        config([
            'textgen.default_model' => null,
            'textgen.models' => [],
            'textgen.pricing' => [
                'input_per_mtok' => 5.0,
                'output_per_mtok' => 25.0,
            ],
            'textgen.modes' => [
                'v1' => ['estimated_tokens' => ['input' => 2000, 'output' => 12000]],
                'v2' => ['estimated_tokens' => ['input' => 3000, 'output' => 18000]],
            ],
        ]);

        // Создаём прогоны: один queued (v1), один running (v2), один done (v1)
        Run::forceCreate([
            'mode' => 'v1',
            'input' => ['mode' => 'v1'],
            'status' => Run::STATUS_QUEUED,
            'cost_usd' => 0.0,
        ]);

        Run::forceCreate([
            'mode' => 'v2',
            'input' => ['mode' => 'v2'],
            'status' => Run::STATUS_RUNNING,
            'cost_usd' => 0.0,
        ]);

        // завершённый прогон — учитывается в spentThisMonth()
        Run::forceCreate([
            'mode' => 'v1',
            'input' => ['mode' => 'v1'],
            'status' => Run::STATUS_DONE,
            'cost_usd' => 1.50, // уже потрачено
            'created_at' => now(),
        ]);

        // Рассчитаем ожидаемую резервную сумму:
        // v1: input 2k -> 2k/1e6 * 5 = 0.01 USD; output 12k -> 12k/1e6 * 25 = 0.3 USD; total = 0.31
        // v2: input 3k -> 3k/1e6 * 5 = 0.015; output 18k -> 18k/1e6 * 25 = 0.45; total = 0.465
        $expectedV1 = (2000/1_000_000.0) * 5.0 + (12000/1_000_000.0) * 25.0; // 0.31
        $expectedV2 = (3000/1_000_000.0) * 5.0 + (18000/1_000_000.0) * 25.0; // 0.465
        $expectedReserved = $expectedV1 + $expectedV2;

        $budget = new BudgetGuard(2.0); // месячный бюджет 2 USD

        $this->assertEqualsWithDelta($expectedReserved, $budget->reserved(), 1e-6, 'Reserved sum mismatch');

        // spentThisMonth() должен учитывать завершённый прогон (1.50)
        $this->assertEqualsWithDelta(1.50, $budget->spentThisMonth(), 1e-6);

        // Проверяем exceeded: spent + reserved >= budget ?
        $sum = $budget->spentThisMonth() + $budget->reserved();
        $this->assertEqualsWithDelta($sum >= 2.0, $budget->exceeded(), 0.0, 'Exceeded flag mismatch');
    }
}
