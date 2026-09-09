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
use Illuminate\Support\Str;

/**
 * GeneratorController
 *
 * CHANGES (summary):
 * - Добавлены методы history() и destroy().
 * - index() теперь поддерживает параметр from={uuid} для предзаполнения формы (повтор).
 * - store() сохраняет denorm поля: model, target_query, session_id.
 * - Все изменения подписаны комментариями CHANGES.
 */
class GeneratorController extends Controller
{
    public function __construct(private readonly BudgetGuard $budget) {}

    /**
     * Форма создания прогона.
     *
     * CHANGES:
     * - Метод принимает Request, поддерживает ?from={uuid} для предзаполнения формы.
     * - Передаёт в view список моделей и defaultModel.
     *
     * @param Request $request
     */
    public function index(Request $request): View
    {
        // Получаем конфиг моделей
        $models = config('textgen.models', []);

        // 1) Попробуем взять явно указанный дефолт в конфиге
        $default = config('textgen.default_model') ?? null;

        // 2) Если не указан, ищем модель с флагом 'default'
        if ($default === null) {
            foreach ($models as $k => $m) {
                if (!empty($m['default'])) {
                    $default = $k;
                    break;
                }
            }
        }

        // 3) Если всё ещё нет — берём первую enabled модель
        if ($default === null) {
            foreach ($models as $k => $m) {
                if (!empty($m['enabled'])) {
                    $default = $k;
                    break;
                }
            }
        }

        // CHANGES: поддержка ?from={uuid} — если указан, подгружаем run и передаём input для prefill
        $prefill = [];
        $from = $request->query('from');
        if ($from) {
            $run = Run::where('uuid', $from)->first();
            if ($run !== null) {
                $prefill = is_array($run->input) ? $run->input : (array) $run->input;
            }
        }

        return view('generator.index', [
            'modes' => config('textgen.modes'),
            'budget' => $this->budget,
            'models' => $models,           // CHANGES: список моделей для селектора
            'defaultModel' => $default,    // CHANGES: дефолт для view
            'prefill' => $prefill,         // CHANGES: данные для предзаполнения формы (повтор)
        ]);
    }

    /**
     * Создать новый прогон.
     *
     * CHANGES:
     * - Фиксируем модель при создании прогона и сохраняем её в поле runs.model.
     * - Сохраняем денормализованный target_query (varchar 200) и session_id.
     *
     * @param StoreRunRequest $request
     */
    public function store(StoreRunRequest $request): RedirectResponse
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

        // -----------------------
        // CHANGES: определяем ключ модели для прогона
        // -----------------------
        // Приоритет: явный выбор в форме -> config default_model -> модель с 'default' -> первая enabled
        $selectedModel = $request->input('model', null);

        if ($selectedModel === null) {
            $selectedModel = config('textgen.default_model') ?? null;
        }

        if ($selectedModel === null) {
            $modelsCfg = config('textgen.models', []);
            foreach ($modelsCfg as $k => $m) {
                if (!empty($m['default'])) {
                    $selectedModel = $k;
                    break;
                }
            }
        }

        if ($selectedModel === null) {
            $modelsCfg = config('textgen.models', []);
            foreach ($modelsCfg as $k => $m) {
                if (!empty($m['enabled'])) {
                    $selectedModel = $k;
                    break;
                }
            }
        }
        // -----------------------

        // CHANGES: денормализованный target_query (varchar 200) для быстрого поиска
        $targetQuery = trim((string) $request->input('target_query', ''));

        // CHANGES: session_id — фиксируем идентификатор сессии для разграничения видимости
        $sessionId = session()->getId();

        $run = Run::create([
            'status' => Run::STATUS_QUEUED,
            'mode' => $request->string('mode')->value(),
            'input' => $request->safe()->all(),
            'ip' => $request->ip(),
            // CHANGES: сохраняем выбранную модель в таблице runs.model
            'model' => $selectedModel,
            // CHANGES: сохраняем денормализованный целевой запрос
            'target_query' => Str::limit($targetQuery, 200),
            // CHANGES: сохраняем идентификатор сессии
            'session_id' => $sessionId,
        ]);

        // Запускаем job; RunPipeline в своей логике должен брать модель из $run->model
        RunPipeline::dispatch($run->id);

        return redirect()->route('runs.show', $run);
    }

    /**
     * Страница истории прогона (GET /history).
     *
     * CHANGES:
     * - Пагинация 25 записей, сортировка created_at desc.
     * - Фильтры: status, mode, model, q (по target_query и input.target_query).
     * - Видимость: учитывается config('textgen.history_scope') = 'session'|'all'.
     *
     * @param Request $request
     */
    public function history(Request $request): View
    {
        $query = Run::query()->orderByDesc('created_at');

        // CHANGES: scope видимости (session|all)
        $scope = config('textgen.history_scope', 'session');
        if ($scope === 'session') {
            $query->where('session_id', session()->getId());
        }

        // Фильтры
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($mode = $request->query('mode')) {
            $query->where('mode', $mode);
        }
        if ($model = $request->query('model')) {
            $query->where('model', $model);
        }
        if ($q = trim((string) $request->query('q', ''))) {
            // Поиск по денормализованному target_query и по input.target_query
            $query->where(function ($qb) use ($q) {
                $qb->where('target_query', 'like', "%{$q}%")
                    ->orWhere('input->target_query', 'like', "%{$q}%");
            });
        }

        $runs = $query->paginate(25)->withQueryString();

        // Для фильтров в UI: статусы, режимы, модели
        $statuses = [
            Run::STATUS_QUEUED => 'Queued',
            Run::STATUS_RUNNING => 'Running',
            Run::STATUS_DONE => 'Done',
            Run::STATUS_FAILED => 'Failed',
        ];
        $modes = config('textgen.modes', []);
        $models = config('textgen.models', []);

        return view('generator.history', [
            'runs' => $runs,
            'statuses' => $statuses,
            'modes' => $modes,
            'models' => $models,
            'scope' => $scope,
        ]);
    }

    /**
     * Удаление прогона (DELETE /run/{run}).
     *
     * CHANGES:
     * - Разрешено удалять только прогоны, принадлежащие текущей сессии,
     *   если history_scope = 'session'. Прямые ссылки на задачу остаются доступными для просмотра.
     *
     * @param Run $run
     */
    public function destroy(Run $run): RedirectResponse
    {
        $scope = config('textgen.history_scope', 'session');

        // Если scope = session, проверяем принадлежность сессии
        if ($scope === 'session' && ! $run->isOwnedBySession(session()->getId())) {
            return back()->withErrors(['run' => 'Вы не можете удалять этот прогон.']);
        }

        $run->delete();

        return redirect()->route('runs.index')->with('status', 'Run deleted');
    }

    /**
     * Показать страницу прогона.
     */
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
            'Content-Type' => $article
                ? 'text/html; charset=UTF-8'
                : 'text/markdown; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Локализованные метки стадий для UI.
     */
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
