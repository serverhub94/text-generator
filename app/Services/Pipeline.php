<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Contracts\TextModel;
use App\Support\ArticleDocument;
use App\Support\KeywordParser;
use RuntimeException;

/**
 * Прогоняет выбранный режим по стадиям.
 *
 * Изменения:
 * - Добавлен колбэк onStageDone для уведомления о завершении каждой стадии.
 * - При isTruncated() теперь не бросается исключение: стадия сохраняется частично,
 *   формируется предупреждение и конвейер корректно останавливается.
 */
final class Pipeline
{
    /** Стадии, которым нужен живой доступ в веб. */
    private const WEB_STAGES = ['research', 'paa', 'entities'];

    /** Потолок выхода для служебных стадий; у статьи он свой, из режима. */
    private const STAGE_TOKENS = [
        'research' => 24000,
        'paa' => 8000,
        'entities' => 12000,
        'brief' => 20000,
        'masterplan' => 20000,
        'audit' => 12000,
    ];

    /** @var callable(string): void */
    private $onStage;

    /** @var callable(string, string, array, float): void */
    private $onStageDone;

    public function __construct(
        private readonly TextModel $claude,
        private readonly PromptRepository $prompts,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {
        $this->onStage = static fn (string $stage) => null;
        $this->onStageDone = static fn (string $stage, string $text, array $usage, float $cost) => null;
    }

    /**
     * Колбэк прогресса — интерфейс показывает, какая стадия идёт сейчас.
     *
     * @param  callable(string): void  $callback
     */
    public function onStage(callable $callback): self
    {
        $this->onStage = $callback;

        return $this;
    }

    /**
     * Колбэк завершения стадии — вызывается после каждой успешно отработавшей стадии.
     *
     * @param callable(string $stage, string $text, array $usage, float $cost): void $callback
     */
    public function onStageDone(callable $callback): self
    {
        $this->onStageDone = $callback;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{stages: array<string, string>, article: string, article_meta: array<string, string>, usage: array<string, int>, cost: float, warnings: array<int, array>}
     */
    public function run(array $input): array
    {
        $mode = $this->mode($input['mode']);
        $vars = $this->vars($input);

        $rules = $this->prompts->rules($vars);

        $messages = [];
        $outputs = [];
        $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'searches' => 0];
        $cost = 0.0;
        $warnings = [];

        foreach ($mode['stages'] as $stage) {
            ($this->onStage)($stage);

            $instructions = $this->instructionsFor($stage, $mode, $input, $vars);

            // Первое сообщение несёт исходные данные; дальше — только задание
            // очередной стадии, роли обязаны чередоваться.
            $messages[] = [
                'role' => 'user',
                'content' => $messages === []
                    ? $this->inputBlock($input, $vars)."\n\n---\n\n".$instructions
                    : $instructions,
            ];

            $result = $this->claude->send(
                rules: $rules,
                messages: $messages,
                effort: (string) $mode['effort'],
                maxTokens: $this->tokensFor($stage, $mode),
                withWebTools: in_array($stage, self::WEB_STAGES, true),
                geo: (string) $input['geo'],
            );

            // guardResult по-прежнему бросает исключения для отказов и пустых ответов
            $this->guardResult($result, $stage);

            if (in_array($stage, self::WEB_STAGES, true)) {
                if ($result->successfulToolResponses === 0) {
                    throw new RuntimeException("Web stage '{$stage}' returned zero successful tool responses (possible max_uses_exceeded or other tool error).");
                }

                if (!empty($result->toolErrors)) {
                    $warnings[] = [
                        'stage' => $stage,
                        'reason' => 'tool_errors',
                        'message' => 'Some web tool calls returned errors; results may be partial.',
                        'errors' => $result->toolErrors,
                        'time' => (string) now(),
                    ];
                }
            }

            // Сохраняем вывод стадии
            $messages[] = ['role' => 'assistant', 'content' => $result->text];
            $outputs[$stage] = $result->text;



            // Накопление usage/cost
            $this->accumulate($usage, $cost, $result);

            // Вызов колбэка завершения стадии (перед возможной остановкой)
            ($this->onStageDone)($stage, $result->text, $usage, $cost);

            // Если стадия усечена по токенам — не бросаем, а останавливаем конвейер и пишем предупреждение
            if ($result->isTruncated()) {
                $warnings[] = [
                    'stage' => $stage,
                    'reason' => 'max_tokens',
                    'message' => "Стадия '{$stage}' упёрлась в потолок токенов (max_tokens). Результат сохранён частично.",
                    'time' => (string) now(),
                ];

                // Прекращаем цикл — возвращаем текущие накопленные данные
                break;
            }
        }

        // Аудит мог забраковать текст — режимы V4/V5 переписывают его.
        if (isset($outputs['audit'], $outputs['article']) && ($mode['rewrites'] ?? 0) > 0) {
            $this->rewriteIfRejected($mode, $vars, $rules, $messages, $outputs, $usage, $cost, $warnings);
        }

        // Статья — HTML-фрагмент, а мета и служебный блок к разметке не
        // относятся: их выносим отдельно, чтобы редактор копировал ровно то,
        // что вставляется в CMS.
        $article = ArticleDocument::parse($outputs['article'] ?? '');


        // Получаем строку html безопасно
        $articleHtml = trim((string) $article->html);

        if ($articleHtml === '') {
            // Парсер очистил разметку — фиксируем предупреждение и помечаем отсутствие статьи
            $warnings[] = [
                'stage' => 'article',
                'reason' => 'empty_article',
                'message' => 'Article markup is empty after parsing; no article will be shown or downloadable.',
                'time' => (string) now(),
            ];

            // Явно помечаем отсутствие статьи в outputs
            $outputs['article'] = null;
        } else {
            $outputs['article'] = $articleHtml;
        }


        return [
            'stages' => $outputs,
            'article' => $article->html,
            'article_meta' => [
                'title' => $article->title,
                'description' => $article->description,
                'url' => $article->url,
                'notes' => $article->notes,
            ],
            'usage' => $usage,
            'cost' => round($cost, 4),
            'warnings' => $warnings,
        ];
    }

    /**
     * Пока аудит выносит «НЕ ПРОШЁЛ», переписываем статью с его замечаниями
     * на руках — но не больше отведённого режимом числа попыток.
     *
     * (Оставлена без изменений; при необходимости можно аналогично вызывать onStageDone внутри)
     */
    private function rewriteIfRejected(
        array $mode,
        array $vars,
        string $rules,
        array &$messages,
        array &$outputs,
        array &$usage,
        float &$cost,
        array &$warnings,
    ): void {
        $attempts = (int) $mode['rewrites'];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {

            // меняем логику вердикта
           /* if (! $this->auditRejected($outputs['audit'])) {
                return;
            }*/
            $verdict = self::parseAuditVerdict($outputs['audit'] ?? '');
            if ($verdict === null) {
                // Не распознали вердикт — не перезапускаем генерацию, а пишем предупреждение
                $warnings[] = [
                    'stage' => 'audit',
                    'reason' => 'audit_verdict_unrecognized',
                    'message' => 'Audit verdict not recognized; skipping rewrite to avoid false positive.',
                    'time' => (string) now(),
                ];
                return;
            }

            if ($verdict !== 'failed') {
                // Если не "НЕ ПРОШЁЛ" — не переписываем
                return;
            }


            ($this->onStage)("rewrite:{$attempt}");

            $messages[] = [
                'role' => 'user',
                'content' => "# Stage: REWRITE (attempt {$attempt} of {$attempts})\n\n"
                    .'Аудит забраковал текст. Перепиши статью целиком, устранив '
                    ."каждое критическое и существенное замечание выше.\n\n"
                    .'Не оправдывайся и не комментируй правки отдельно — выдай '
                    .'готовую статью в том же формате, что и раньше: те же три '
                    .'секции и тот же чистый HTML без атрибутов. Если '
                    .'замечание требует данных, которых нет, перепиши '
                    .'предложение так, чтобы оно не требовало этих данных, '
                    .'либо поставь <strong>добавьте данные</strong>.',
            ];

            $article = $this->claude->send(
                rules: $rules,
                messages: $messages,
                effort: (string) $mode['effort'],
                maxTokens: (int) $mode['max_tokens'],
            );

            $this->guardResult($article, 'rewrite');
            $messages[] = ['role' => 'assistant', 'content' => $article->text];
            $outputs['article'] = $article->text;
            $this->accumulate($usage, $cost, $article);

            // Вызов колбэка завершения стадии для переписывания
            ($this->onStageDone)("rewrite:{$attempt}", $article->text, $usage, $cost);

            // Перепроверяем — иначе «до 2 раз» превращается в «один раз и на удачу».
            ($this->onStage)("audit:{$attempt}");

            $messages[] = [
                'role' => 'user',
                'content' => $this->prompts->stage('audit', $vars),
            ];

            $audit = $this->claude->send(
                rules: $rules,
                messages: $messages,
                effort: (string) $mode['effort'],
                maxTokens: self::STAGE_TOKENS['audit'],
            );

            $this->guardResult($audit, 'audit');
            $messages[] = ['role' => 'assistant', 'content' => $audit->text];
            $outputs['audit'] = $audit->text;
            $this->accumulate($usage, $cost, $audit);

            // Вызов колбэка завершения стадии аудита
            ($this->onStageDone)("audit:{$attempt}", $audit->text, $usage, $cost);
        }
    }

    /**
     * Вердикт аудита. Ищем именно «НЕ ПРОШЁЛ» — «ПРОШЁЛ С ЗАМЕЧАНИЯМИ»
     * переписывания не требует.
     */
    private function auditRejected(string $audit): bool
    {
        return preg_match('/\bНЕ\s+ПРОШ[ЁЕ]Л\b/u', $audit) === 1;
    }


    /**
     * Разбирает текст аудита и возвращает один из: 'passed', 'passed_with_remarks', 'failed', или null если не распознано.
     */
    public static function parseAuditVerdict(string $auditText): ?string
    {
        // Нормализуем переносы и кодировку
        $text = str_replace(["\r\n", "\r"], "\n", $auditText);
        // Найдём заголовок "## Вердикт" (регистронезависимо)
        $pos = mb_stripos($text, '## вердикт');
        if ($pos === false) {
            // Если заголовка нет — попробуем искать в начале текста первые строки
            $after = $text;
        } else {
            $after = mb_substr($text, $pos + mb_strlen('## вердикт'));
        }

        // Разбиваем на строки и ищем первую значимую
        $lines = preg_split("/\n/", $after);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Игнорируем строки, которые явно являются форматом с перечислением через |
            if (strpos($line, '|') !== false) {
                continue;
            }
            // Убираем возможные маркеры и кавычки
            $clean = mb_strtoupper(preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $line));
            // Сопоставляем ключевые фразы
            if (mb_strpos($clean, 'НЕ ПРОШ') !== false) {
                return 'failed';
            }
            if (mb_strpos($clean, 'ПРОШЁЛ С ЗАМЕЧАНИЯМИ') !== false || mb_strpos($clean, 'ПРОШЕЛ С ЗАМЕЧАНИЯМИ') !== false) {
                return 'passed_with_remarks';
            }
            if (mb_strpos($clean, 'ПРОШ') !== false || mb_strpos($clean, 'ПРОШЁЛ') !== false || mb_strpos($clean, 'ПРОШЕЛ') !== false) {
                return 'passed';
            }
            // Если строка не распознана — прекращаем поиск и вернём null
            return null;
        }

        return null;
    }


    /**
     * @param  array<string, mixed>  $mode
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $vars
     */
    private function instructionsFor(string $stage, array $mode, array $input, array $vars): string
    {
        $instructions = $this->prompts->stage($stage, $vars);

        // Характер текста задаёт режим — подмешиваем его только к статье.
        if ($stage === 'article') {
            $instructions .= "\n\n---\n\n".$this->prompts->mode($input['mode'], $vars);

            if (($mode['require_responsible_gambling'] ?? false) === true) {
                $instructions .= "\n\n**Блок responsible gambling обязателен.**";
            }
        }

        return $instructions;
    }

    /**
     * @param  array<string, mixed>  $mode
     */
    private function tokensFor(string $stage, array $mode): int
    {
        return $stage === 'article'
            ? (int) $mode['max_tokens']
            : self::STAGE_TOKENS[$stage] ?? 16000;
    }

    /**
     * Исходные данные, как их дал человек. Всё, что здесь не перечислено,
     * модель обязана пометить «нет данных» — на этом держится весь смысл.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $vars
     */
    private function inputBlock(array $input, array $vars): string
    {
        $keywords = KeywordParser::parse($input['keywords'] ?? null);

        $competitors = trim((string) ($input['competitors'] ?? ''));
        $queries = trim((string) ($input['target_queries'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));

        return implode("\n\n", array_filter([
            '# SUPPLIED DATA',
            'Всё числовое ниже вставлено человеком из Ahrefs. Это единственный '
            .'допустимый источник цифр. Чего здесь нет — «нет данных».',
            "## Target query\n".$vars['target_query'],
            "## Market\nGEO: {$vars['geo']}\nЯзык: {$vars['language']}",
            "## Domain\n".($vars['domain'] !== '' ? $vars['domain'] : 'не указан'),
            $queries !== '' ? "## Целевые запросы\n".$queries : null,
            "## Семантика с частотностью\n".KeywordParser::toPromptTable($keywords),
            $competitors !== '' ? "## Конкуренты на анализ\n".$competitors : null,
            $notes !== '' ? "## Дополнительно от редактора\n".$notes : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function vars(array $input): array
    {
        return [
            'target_query' => trim((string) $input['target_query']),
            'geo' => strtoupper(trim((string) $input['geo'])),
            'language' => trim((string) ($input['language'] ?? '')),
            'domain' => trim((string) ($input['domain'] ?? '')),
            'page_type' => trim((string) ($input['page_type'] ?? 'не указан')),
            'site_type' => trim((string) ($input['site_type'] ?? 'партнёрский казино-сайт')),
            'page_goal' => trim((string) ($input['page_goal'] ?? 'не указана')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mode(string $mode): array
    {
        $modes = $this->config['modes'];

        if (! isset($modes[$mode])) {
            throw new RuntimeException("Неизвестный режим: {$mode}");
        }

        return $modes[$mode];
    }

    private function guardResult(ClaudeResult $result, string $stage): void
    {
        if ($result->isRefusal()) {
            throw new RuntimeException(
                "Модель отказалась выполнять стадию «{$stage}». Проверьте "
                .'формулировку запроса и вводные.'
            );
        }

        // Пустой текст — фатальная ошибка
        if (trim($result->text) === '') {
            throw new RuntimeException("Стадия «{$stage}» вернула пустой ответ.");
        }
    }

    /**
     * @param  array<string, int>  $usage
     */
    private function accumulate(array &$usage, float &$cost, ClaudeResult $result): void
    {
        $usage['input'] += $result->inputTokens;
        $usage['output'] += $result->outputTokens;
        $usage['cache_read'] += $result->cacheReadTokens;
        $usage['cache_write'] += $result->cacheWriteTokens;
        $usage['searches'] += $result->webSearches;
        $cost += $result->costUsd;
    }
}
