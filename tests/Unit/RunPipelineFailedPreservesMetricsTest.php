<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Jobs\RunPipeline;
use App\Models\Run;
use Exception;

final class RunPipelineFailedPreservesMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function testFailedDoesNotOverwriteCostUsageStages(): void
    {
        // Создаём запись Run с уже накопленными значениями.
        // Используем forceCreate чтобы не зависеть от $fillable модели.
        $run = Run::forceCreate([
            'input' => ['mode' => 'v1'],
            'mode' => 'v1',                 // <- обязательно
            'status' => Run::STATUS_QUEUED,
            'stages' => ['research' => 'partial text'],
            'usage' => ['input' => 100, 'output' => 200],
            'cost_usd' => 12.34,
        ]);

        // Предусловия — если они не выполняются, тест даст понятную ошибку
        $this->assertNotNull($run->id, 'Run was not created');
        $this->assertEquals(12.34, (float) $run->cost_usd, 'Precondition failed: cost_usd not saved correctly');
        $this->assertArrayHasKey('research', (array) $run->stages, 'Precondition failed: stages not saved correctly');
        $this->assertSame(100, $run->usage['input'], 'Precondition failed: usage not saved correctly');

        // Вызов failed()
        $job = new RunPipeline($run->id);
        $job->failed(new Exception('boom test'));

        // Перечитываем модель из БД
        $run->refresh();

        // Ожидаемый статус — используем константу из модели
        $expectedStatus = Run::STATUS_FAILED;

        $this->assertSame($expectedStatus, $run->status, 'Status was not set to failed as expected');
        $this->assertStringContainsString('boom test', (string) $run->error, 'Error message not saved');

        // Критично: cost_usd, usage и stages не должны быть перезаписаны
        $this->assertEquals(12.34, (float) $run->cost_usd, 'cost_usd was unexpectedly changed');
        $this->assertArrayHasKey('research', (array) $run->stages, 'stages was unexpectedly changed');
        $this->assertSame(100, $run->usage['input'], 'usage was unexpectedly changed');

        // finished_at должен быть установлен
        $this->assertNotNull($run->finished_at, 'finished_at should be set');
    }
}
