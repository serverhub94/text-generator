<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\ClaudeService;
use App\Services\Pipeline;
use App\Services\Contracts\TextModel;
use App\Services\PromptRepository;
use App\Support\BudgetGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register0(): void
    {
        $this->app->singleton(Client::class, function () {
            $key = (string) config('textgen.api_key');

            // Без ключа приложение бессмысленно — падаем сразу и внятно,
            // а не на середине первого прогона с 401 из SDK.
            if ($key === '') {
                throw new RuntimeException(
                    'ANTHROPIC_API_KEY не задан в .env — генератор не запустится.'
                );
            }

            return new Client(apiKey: $key);
        });

        $this->app->singleton(PromptRepository::class, fn () => new PromptRepository(
            resource_path('prompts'),
        ));

        $this->app->singleton(ClaudeService::class, fn ($app) => new ClaudeService(
            $app->make(Client::class),
            config('textgen'),
        ));

        $this->app->alias(ClaudeService::class, TextModel::class);

        $this->app->bind(Pipeline::class, fn ($app) => new Pipeline(
            $app->make(TextModel::class),
            $app->make(PromptRepository::class),
            config('textgen'),
        ));

        $this->app->singleton(BudgetGuard::class, fn () => new BudgetGuard(
            (float) config('textgen.limits.monthly_budget_usd'),
        ));

        $this->app->singleton(\App\Services\AI\AiClientFactory::class, function($app) {
            return new \App\Services\AI\AiClientFactory($app);
        });
    }

    public function register2(): void
    {
        // PromptRepository и BudgetGuard — всегда
        $this->app->singleton(PromptRepository::class, fn () => new PromptRepository(resource_path('prompts')));
        $this->app->singleton(BudgetGuard::class, fn () => new BudgetGuard((float) config('textgen.limits.monthly_budget_usd')));

        // Фабрика всегда
        $this->app->singleton(\App\Services\AI\AiClientFactory::class, function ($app) {
            return new \App\Services\AI\AiClientFactory($app);
        });

        // Claude — только если есть ключ
        $anthropicKey = (string) config('textgen.api_key', '');
        if ($anthropicKey !== '') {
            $this->app->singleton(\Anthropic\Client::class, fn() => new \Anthropic\Client(apiKey: $anthropicKey));
            $this->app->singleton(ClaudeService::class, fn($app) => new ClaudeService($app->make(\Anthropic\Client::class), config('textgen')));
            // НЕ делаем alias TextModel => ClaudeService
        }

        // Gemini — только если есть ключ
        $geminiKey = (string) (config('services.gemini.key') ?? config('textgen.gemini_api_key') ?? '');
        if ($geminiKey !== '') {

            $this->app->bind(\App\Services\AI\GeminiService::class, function ($app) {
                $guzzle = new \GuzzleHttp\Client();
                $cfg = config('services.gemini', []) + config('textgen.gemini', []);
                return new \App\Services\AI\GeminiService($guzzle, $cfg);
            });
        }
    }

    public function register(): void
    {
        // PromptRepository и BudgetGuard — всегда
        $this->app->singleton(PromptRepository::class, fn () => new PromptRepository(resource_path('prompts')));
        $this->app->singleton(BudgetGuard::class, fn () => new BudgetGuard((float) config('textgen.limits.monthly_budget_usd')));

        // Фабрика всегда
        $this->app->singleton(\App\Services\AI\AiClientFactory::class, function ($app) {
            return new \App\Services\AI\AiClientFactory($app);
        });

        // Никаких автоматических биндингов конкретных провайдеров здесь нет.
        // Регистрация конкретных сервисов будет выполняться явно там, где это нужно (контроллер/валидация/job).
    }



    public function boot(): void
    {
        $limits = config('textgen.limits');

    //    $limits['runs_per_hour_per_ip'] = 1000;
        // Два окна вместо одного: часовое ловит разгон, суточное ловит
        // равномерное выцеживание бюджета в течение дня.
        RateLimiter::for('textgen-hour', fn (Request $request) => Limit::perHour(
            (int) $limits['runs_per_hour_per_ip'],
        )->by($request->ip())->response(fn () => back()->withInput()->withErrors([
            'mode' => 'Слишком много запусков за час. Подождите и попробуйте снова.',
        ])));

        RateLimiter::for('textgen-day', fn (Request $request) => Limit::perDay(
            (int) $limits['runs_per_day_per_ip'],
        )->by($request->ip())->response(fn () => back()->withInput()->withErrors([
            'mode' => 'Исчерпан дневной лимит запусков с этого адреса.',
        ])));
    }
}
