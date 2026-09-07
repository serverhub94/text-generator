<?php
namespace App\Services\AI;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use RuntimeException;

final class AiClientFactory
{
    public function __construct(private Container $app) {}

    public function make0(string $name)
    {
        $name = strtolower(trim($name));

        return match ($name) {
            'claude' => $this->app->bound(\App\Services\ClaudeService::class)
                ? $this->app->make(\App\Services\ClaudeService::class)
                : throw new RuntimeException('Claude is not configured: API_KEY (textgen.api_key) is missing.'),
            'gemini' => $this->app->bound(\App\Services\AI\GeminiService::class)
                ? $this->app->make(\App\Services\AI\GeminiService::class)
                : throw new RuntimeException('Gemini is not configured: GEMINI_API_KEY (services.gemini.key or textgen.gemini_key) is missing.'),
            default => throw new InvalidArgumentException("Unknown AI provider: {$name}"),
        };
    }

    public function make(string $name)
    {
        $name = strtolower(trim((string) $name));
        if ($name === '') {
            throw new \RuntimeException('AI model name is empty.');
        }

        return match ($name) {
            'claude' => $this->makeClaude(),
            'gemini' => $this->makeGemini(),
            default => throw new \InvalidArgumentException("Unknown AI provider: {$name}"),
        };
    }

    private function makeClaude()
    {
        $key = config('textgen.api_key', '');

        if (empty($key)) {
            throw new \RuntimeException('Claude is not configured: API_KEY is missing.');
        }

        $client = new \Anthropic\Client(apiKey: $key);
// ошибка 500
        return new \App\Services\ClaudeService($client, config('textgen'));
    }

    private function makeGemini()
    {
        $key = config('services.gemini.key') ?? config('textgen.gemini_api_key');
        if (empty($key)) {
            throw new \RuntimeException('Gemini is not configured: GEMINI_API_KEY is missing.');
        }

        $guzzle = new \GuzzleHttp\Client();
        $cfg = config('services.gemini', []) + config('textgen.gemini', []);
        return new \App\Services\AI\GeminiService($guzzle, $cfg);
    }

}
