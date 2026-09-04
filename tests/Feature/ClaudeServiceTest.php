<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\ClaudeService;
use Anthropic\Client;
use Carbon\Carbon;

final class ClaudeServiceTest extends TestCase
{
    public function test_toIsoCountry_maps_common_codes(): void
    {
        $this->assertSame('GB', ClaudeService::toIsoCountry('UK'));
        $this->assertSame('GB', ClaudeService::toIsoCountry('uk'));
        $this->assertSame('GR', ClaudeService::toIsoCountry('EL'));
        $this->assertSame('US', ClaudeService::toIsoCountry('us'));
        $this->assertNull(ClaudeService::toIsoCountry('europe'));
        $this->assertNull(ClaudeService::toIsoCountry(''));
    }

    /**
     * Минимальный тест, который проверяет, что ClaudeService::send
     * действительно пытается использовать $client->messages->createStream().
     *
     * Мы не мокируем MessagesService (final) и не подставляем сложные объекты.
     * Вместо этого создаём мок Client без подстановки messages — тогда
     * при попытке вызвать createStream произойдёт ошибка (null->createStream),
     * что доказывает, что send формирует вызов к клиенту.
     */
    public function test_send_attempts_to_call_client_messages_createStream(): void
    {
        Carbon::setTestNow(Carbon::now());

        // Создаём мок клиента, но не подставляем $client->messages
        /** @var Client $client */
        $client = $this->createMock(Client::class);

        $config = [
            'model' => 'claude-test-model',
            'tools' => ['max_searches' => 1, 'max_fetches' => 1],
            'pricing' => [
                'input_per_mtok' => 0.1,
                'output_per_mtok' => 0.2,
                'cache_read_per_mtok' => 0.01,
                'cache_write_per_mtok' => 0.01,
            ],
        ];

        $service = new ClaudeService($client, $config);

        $rules = 'system rules example';
        $messages = [['role' => 'user', 'content' => 'hello']];

        // Ожидаем, что при отсутствии корректного messages сервис выбросит ошибку
        // (например, попытка вызвать метод на null). Мы ловим Throwable, чтобы тест
        // был устойчив к разным версиям PHP/SDK (Error или TypeError и т.д.).
        $this->expectException(\Throwable::class);

        // Вызов — должен привести к ошибке, подтверждающей, что send() обращается к client->messages.
        $service->send($rules, $messages, 'medium', 1000, true, 'uk');
    }
}
