<?php

declare(strict_types=1);

namespace Tests\Feature;

use Anthropic\Client;
use RuntimeException;
use Tests\TestCase;

class ConfigCacheTest extends TestCase
{
    /**
     * DEPLOY.md рекомендует `php artisan config:cache`. После него env() за
     * пределами config-файлов возвращает null — если читать ключ через env()
     * в провайдере, прод падает ровно после рекомендованной оптимизации.
     * Поэтому ключ обязан приходить из config.
     */
    public function test_the_api_key_comes_from_config_not_from_env_at_runtime(): void
    {
        config(['textgen.api_key' => 'sk-ant-из-конфига']);

        $this->assertInstanceOf(Client::class, $this->app->make(Client::class));
    }

    public function test_a_missing_key_fails_with_a_clear_message(): void
    {
        config(['textgen.api_key' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ANTHROPIC_API_KEY не задан');

        $this->app->make(Client::class);
    }

    public function test_no_runtime_code_reads_env_directly(): void
    {
        // Ловим регрессию на будущее: env() допустим только в config/.
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path()),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/(?<![\w>$])env\s*\(/', $contents) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, 'env() в app/ ломается после config:cache');
    }
}
