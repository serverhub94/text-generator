<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreRunRequest;
use App\Jobs\RunPipeline;
use App\Models\Run;
use App\Support\BudgetGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

class GeneratorController extends Controller
{
    public function __construct(private readonly BudgetGuard $budget) {}

    public function index(): View
    {
        return view('generator.index', [
            'modes' => config('textgen.modes'),
            'budget' => $this->budget,
        ]);
    }

    public function store0(StoreRunRequest $request): RedirectResponse
    {

        if ($this->budget->exceeded()) {
            return back()
                ->withInput()
                ->withErrors([
                    'mode' => sprintf(
                        'Месячный потолок расходов исчерпан (%.2f из %.2f USD). '
                        .'Поднимите TEXTGEN_MONTHLY_BUDGET_USD или дождитесь следующего месяца.',
                        $this->budget->spentThisMonth(),
                        $this->budget->budget(),
                    ),
                ]);
        }

        $run = Run::create([
            'status' => Run::STATUS_QUEUED,
            'mode' => $request->string('mode')->value(),
            'input' => $request->safe()->all(),
            'ip' => $request->ip(),
        ]);

        RunPipeline::dispatch($run->id);

        return redirect()->route('runs.show', $run);
    }

    public function store1(StoreRunRequest $request)
    {
        $aiModel = $request->input('ai_model', config('textgen.default_ai', ''));

        // Попытка зарегистрировать/создать клиент заранее:
        // Здесь мы явно регистрируем конкретный сервис в контейнере при необходимости,
        // либо просто проверяем, что фабрика сможет его создать.
        try {
            // Если вы хотите регистрировать конкретный сервис динамически — делайте это здесь.
            // Пример: если gemini выбран, регистрируем GeminiService только если есть ключ.
            if ($aiModel === 'gemini') {
                $geminiKey = (string) (config('services.gemini.key') ?? config('textgen.gemini_api_key') ?? '');
                if ($geminiKey !== '') {
                    app()->bind(\App\Services\AI\GeminiService::class, function ($app) {
                        $guzzle = new \GuzzleHttp\Client();
                        $cfg = config('services.gemini', []) + config('textgen.gemini', []);
                        return new \App\Services\AI\GeminiService($guzzle, $cfg);
                    });
                }
            }

            if ($aiModel === 'claude') {
                $anthropicKey = (string) config('textgen.api_key', '');
                if ($anthropicKey !== '') {
                    app()->singleton(\Anthropic\Client::class, fn() => new \Anthropic\Client(apiKey: $anthropicKey));
                    app()->singleton(\App\Services\ClaudeService::class, fn($app) => new \App\Services\ClaudeService($app->make(\Anthropic\Client::class), config('textgen')));
                }
            }

            // Проверяем, сможет ли фабрика создать нужный клиент
            app(\App\Services\AI\AiClientFactory::class)->make($aiModel);
        } catch (\Throwable $e) {
            return back()->withErrors(['ai_model' => 'Selected AI provider is not configured: ' . $e->getMessage()]);
        }

        // Всё ок — сохраняем input и создаём Run
        $safe = $request->safe()->all();
        $safe['ai_model'] = $aiModel;

        $run = Run::create([
            'status' => Run::STATUS_QUEUED,
            'mode' => $request->string('mode')->value(),
            'input' => $safe,
            'ip' => $request->ip(),
        ]);

        // Принудительно на database, чтобы исключить скрытый sync
       /// RunPipeline::dispatch($run->id)->onConnection('database')->onQueue('default');
        ///
        RunPipeline::dispatch($run->id);


        return redirect()->route('runs.show', $run);
    }

    public function store(StoreRunRequest $request)
    {
        if ($this->budget->exceeded()) {
            return back()
                ->withInput()
                ->withErrors([
                    'mode' => sprintf(
                        'Месячный потолок расходов исчерпан (%.2f из %.2f USD). '
                        .'Поднимите TEXTGEN_MONTHLY_BUDGET_USD или дождитесь следующего месяца.',
                        $this->budget->spentThisMonth(),
                        $this->budget->budget(),
                    ),
                ]);
        }
        $aiModel = $request->input('ai_model', config('textgen.default_ai', ''));

        // Проверяем заранее — фабрика бросит исключение, если провайдер не настроен
        try {
            app(\App\Services\AI\AiClientFactory::class)->make($aiModel);
        } catch (\Throwable $e) {
           return back()->withErrors(['ai_model' => 'Method Store Exeption: ' . $e->getMessage()]);
        }

        $safe = $request->safe()->all();
        $safe['ai_model'] = $aiModel;

        $run = Run::create([
            'status' => Run::STATUS_QUEUED,
            'mode' => $request->string('mode')->value(),
            'input' => $safe,
            'ip' => $request->ip(),
        ]);


        RunPipeline::dispatch($run->id);
      //  RunPipeline::dispatchSync($run->id);

        return redirect()->route('runs.show', $run);
    }



    public function show(Run $run): View
    {
        return view('generator.show', [
            'run' => $run,
            'mode' => config("textgen.modes.{$run->mode}"),
        ]);
    }

    /**
     * Опрос статуса из браузера, пока конвейер идёт в очереди.
     */
    public function status(Run $run): JsonResponse
    {
        return response()->json([
            'status' => $run->status,
            'stage' => $run->stage,
            'stage_label' => $this->stageLabel($run->stage),
            'finished' => $run->isFinished(),
            'error' => $run->error,
            'cost' => $run->cost_usd,
        ]);
    }

    /**
     * Отдаём стадию отдельным файлом — ресёрч-отчёт и ТЗ являются
     * самостоятельными деливерингами, не только статья.
     *
     * Статья уходит как .html: это готовый фрагмент для вставки между <body>
     * и </body>, остальные стадии — рабочие документы в markdown.
     */
    public function download(Run $run, string $part): Response
    {
        $article = $part === 'article';

        $content = $article
            ? (string) $run->article
            : (string) $run->stageOutput($part);

        abort_if($content === '', 404);

        // Запрос бывает на языке, от которого slug() не оставляет ничего
        // (кириллица, греческий) — тогда имя файла собираем из uuid.
        $slug = str($run->input['target_query'] ?? '')->slug()->value();
        $slug = $slug !== '' ? $slug : substr($run->uuid, 0, 8);

        $extension = $article ? 'html' : 'md';
        $filename = "{$slug}-{$part}.{$extension}";

        return response($content, 200, [
            // Фрагмент, а не документ: скачанный файл открывается в браузере
            // криво и это нормально — его вставляют в CMS, а не публикуют.
            'Content-Type' => $article
                ? 'text/html; charset=UTF-8'
                : 'text/markdown; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function stageLabel(?string $stage): ?string
    {
        if ($stage === null) {
            return null;
        }

        // Стадии переписывания приходят как «rewrite:1» — показываем номер.
        if (str_contains($stage, ':')) {
            [$name, $attempt] = explode(':', $stage, 2);

            return match ($name) {
                'rewrite' => "Переписываю статью после аудита (попытка {$attempt})",
                'audit' => "Повторный аудит (попытка {$attempt})",
                default => $stage,
            };
        }

        return match ($stage) {
            'entities' => 'Разбираю сущности',
            'research' => 'Ресёрч: выдача, конкуренты, content gap',
            'paa' => 'Собираю PAA-вопросы Google',
            'masterplan' => 'Строю мастер-план страницы',
            'brief' => 'Собираю ТЗ',
            'article' => 'Пишу текст',
            'audit' => 'Аудит текста',
            default => $stage,
        };
    }
}
