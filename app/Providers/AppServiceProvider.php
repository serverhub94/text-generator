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
    public function register(): void
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
