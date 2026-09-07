<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ClaudeServiceCacheTtlAndPricingTest extends TestCase
{
    public function testConfigCacheWriteIsTwoTimesInputDefault(): void
    {
        $configPath = __DIR__ . '/../../config/textgen.php';
        $this->assertFileExists($configPath, 'config/textgen.php not found');
        $config = require $configPath;

        $this->assertIsArray($config, 'config/textgen.php did not return an array');
        $this->assertArrayHasKey('pricing', $config, 'pricing section missing in config/textgen.php');

        $pricing = $config['pricing'];
        $this->assertArrayHasKey('input_per_mtok', $pricing, 'input_per_mtok missing');
        $this->assertArrayHasKey('cache_write_per_mtok', $pricing, 'cache_write_per_mtok missing');

        $input = (float) $pricing['input_per_mtok'];
        $cacheWrite = (float) $pricing['cache_write_per_mtok'];

        $this->assertGreaterThanOrEqual(
            2 * $input - 1e-9,
            $cacheWrite,
            sprintf('Expected cache_write_per_mtok >= 2 * input_per_mtok, got %s vs %s', $cacheWrite, $input)
        );
    }

    public function testClaudeServiceContainsCacheTtlOneHour(): void
    {
        $path = __DIR__ . '/../../app/Services/ClaudeService.php';
        $this->assertFileExists($path, 'app/Services/ClaudeService.php not found');

        $content = file_get_contents($path);
        $this->assertIsString($content);

        // Проверяем несколько вариантов записи ttl => '1h' рядом с cacheControl
        $candidates = [
            "cacheControl: ['type' => 'ephemeral', 'ttl' => '1h']",
            "cacheControl: [ 'type' => 'ephemeral', 'ttl' => '1h' ]",
            "cacheControl: ['type' => 'ephemeral','ttl' => '1h']",
            "cacheControl: [\"type\" => \"ephemeral\", \"ttl\" => \"1h\"]",
            "'ttl' => '1h'",
            '"ttl" => "1h"',
            "ttl' => '1h'",
            'ttl" => "1h"',
        ];

        $found = false;
        foreach ($candidates as $needle) {
            if (strpos($content, $needle) !== false) {
                $found = true;
                break;
            }
        }

        // Ещё вариант: искать "ttl" => '1h' без привязки к cacheControl
        if (! $found) {
            if (strpos($content, "ttl") !== false && (strpos($content, "=> '1h'") !== false || strpos($content, '=> "1h"') !== false)) {
                $found = true;
            }
        }

        $this->assertTrue($found, "Expected ClaudeService::createStream to include cacheControl with ttl => '1h'.");
    }
}
