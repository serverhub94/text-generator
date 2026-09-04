<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\DatabaseQueue;
use Carbon\Carbon;

class PreventDoubleProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_does_not_repick_job_when_reserved_at_within_retry_after(): void
    {
        // Зафиксируем "сейчас" для предсказуемости
        Carbon::setTestNow(Carbon::now());

        // Минимальный payload для записи в jobs
        $payload = json_encode([
            'displayName' => 'Tests\FakeJob',
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => null,
            'timeout' => null,
            'data' => ['commandName' => 'Tests\FakeJob', 'command' => null],
        ]);

        // Установим reserved_at так, чтобы оно было внутри retryAfter (меньше 3700 секунд назад)
        // Например, 1 час назад = 3600s < 3700s
        $oneHourAgo = Carbon::now()->subHour()->getTimestamp();

        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => $payload,
            'attempts'     => 1,
            'reserved_at'  => $oneHourAgo,
            'available_at' => Carbon::now()->getTimestamp(),
            'created_at'   => Carbon::now()->getTimestamp(),
        ]);

        // Получаем соединение из контейнера, чтобы DatabaseQueue использовал то же соединение
        $connection = $this->app['db']->connection();
        $table = 'jobs';
        $queueName = 'default';
        $retryAfter = 3700;

        $queue = new DatabaseQueue($connection, $table, $queueName, $retryAfter);

        // Попытка получить задачу из очереди — ожидаем, что задача НЕ будет взята повторно
        $job = $queue->pop($queueName);

        $this->assertNull($job, 'Ожидалось, что воркер не возьмёт задачу повторно, но pop() вернул объект.');
    }
}
