<?php

/**
 * Конфигурация генератора текстов.
 *
 * Режимы V1-V7 повторяют лестницу качества из docs/ — см. CLAUDE.md.
 * Каждый режим задаёт: какие стадии конвейера прогоняются, с каким effort
 * и с каким потолком выходных токенов.
 */

return [

    /*
     | Ключ читается здесь, а не через env() в коде: после `config:cache`
     | env() за пределами config-файлов возвращает null, и приложение
     | падало бы на проде ровно после рекомендованной оптимизации.
     */
    'api_key' => env('ANTHROPIC_API_KEY', ''),

    'model' => env('TEXTGEN_MODEL', 'claude-opus-5'),
    'default_model' => env('TEXTGEN_MODEL', 'claude-opus-5'),

    /*
     | Версии серверных инструментов Anthropic. Вынесены в конфиг, чтобы
     | поднимать версию без правки кода, когда выходит новая.
     */
    'tools' => [
        'web_search' => env('TEXTGEN_WEB_SEARCH_TOOL', 'web_search_20260209'),
        'web_fetch' => env('TEXTGEN_WEB_FETCH_TOOL', 'web_fetch_20260209'),
        'max_searches' => (int) env('TEXTGEN_MAX_SEARCHES', 12),
        'max_fetches' => (int) env('TEXTGEN_MAX_FETCHES', 10),
    ],

    /*
     | Защита от расхода ключа. Сабдомен открыт, поэтому лимиты — единственное,
     | что стоит между API-ключом и случайным гостем.
     */
    'limits' => [
        // Пусто = код доступа выключен. Заполните, чтобы закрыть форму.
        'access_code' => env('TEXTGEN_ACCESS_CODE', ''),
        'runs_per_hour_per_ip' => (int) env('TEXTGEN_RUNS_PER_HOUR', 5),
        'runs_per_day_per_ip' => (int) env('TEXTGEN_RUNS_PER_DAY', 20),
        // Потолок расходов в месяц, USD. 0 = без потолка.
        'monthly_budget_usd' => (float) env('TEXTGEN_MONTHLY_BUDGET_USD', 200),
        // Через сколько дней удалять прогоны (истории в UI нет, но очередь
        // требует персистентности — храним ровно столько, сколько нужно).
        'prune_after_days' => (int) env('TEXTGEN_PRUNE_AFTER_DAYS', 7),
    ],

    /*
     | Цены Claude Opus 5, USD за 1M токенов. Нужны только для подсчёта
     | израсходованного бюджета — на запросы не влияют.
     */
    'pricing' => [
        'input_per_mtok' => (float) env('TEXTGEN_PRICE_INPUT', 5.00),
        'output_per_mtok' => (float) env('TEXTGEN_PRICE_OUTPUT', 25.00),
        // cache write — по умолчанию 2x от input_per_mtok (5.00 * 2 = 10.00)
        'cache_write_per_mtok' => (float) env('TEXTGEN_PRICE_CACHE_WRITE', 10.00),
        'cache_read_per_mtok' => (float) env('TEXTGEN_PRICE_CACHE_READ', 0.50),
    ],

    /*
     | Режимы генерации.
     |
     | stages  — какие стадии конвейера выполняются по порядку.
     | effort  — глубина рассуждения на стадии статьи.
     | max_tokens — потолок выхода стадии статьи.
     | rewrites — сколько раз перегенерировать, если аудит не прошёл порог.
     */
    'modes' => [

        'v1' => [
            'label' => 'V1 · Базовый',
            'speed' => 'Быстро',
            'summary' => 'Стандартная генерация text_review. Для большого объёма простых обзоров с несколькими брендами.',
            'stages' => ['article'],
            'effort' => 'low',
            'max_tokens' => 16000,
            'research' => false,
            // Оценка: сколько токенов примерно уйдёт на input/output
            'estimated_tokens' => [
                'input' => 2000,   // пример: 2k input tokens
                'output' => 12000, // пример: 12k output tokens
            ],
        ],

        'v2' => [
            'label' => 'V2 · Улучшенный',
            'speed' => 'Средне',
            'summary' => 'Расширенная H2/H3 структура, детализированные описания брендов. Баланс скорости и качества.',
            'stages' => ['brief', 'article'],
            'effort' => 'medium',
            'max_tokens' => 24000,
            'research' => false,
            // Оценка: сколько токенов примерно уйдёт на input/output
            'estimated_tokens' => [
                'input' => 2000,   // пример: 2k input tokens
                'output' => 12000, // пример: 12k output tokens
            ],
        ],

        'v3' => [
            'label' => 'V3 · Максимальный',
            'speed' => 'Дольше',
            'summary' => 'SERP-анализ конкурентов + уникализация структуры. Наилучшее качество, релевантное запросу.',
            'stages' => ['research', 'brief', 'article'],
            'effort' => 'high',
            'max_tokens' => 32000,
            'research' => true,
            // Оценка: сколько токенов примерно уйдёт на input/output
            'estimated_tokens' => [
                'input' => 2000,   // пример: 2k input tokens
                'output' => 12000, // пример: 12k output tokens
            ],
        ],

        'v4' => [
            'label' => 'V4 · Pro + Проверка',
            'speed' => 'Медленно',
            'summary' => 'V3 + автоматическая проверка уникальности. Если ниже порога — автоперегенерация (до 2 раз).',
            'stages' => ['research', 'brief', 'article', 'audit'],
            'effort' => 'high',
            'max_tokens' => 32000,
            'research' => true,
            'rewrites' => 2,
            'originality_threshold' => 80,
            // Оценка: сколько токенов примерно уйдёт на input/output
            'estimated_tokens' => [
                'input' => 2000,   // пример: 2k input tokens
                'output' => 12000, // пример: 12k output tokens
            ],
        ],

        'v5' => [
            'label' => 'V5 · PAA + E-E-A-T',
            'speed' => 'Макс',
            'summary' => 'V4 + реальные PAA-вопросы Google, диверсификация анкоров, блок responsible gambling, self-check отчёт.',
            'stages' => ['research', 'paa', 'brief', 'article', 'audit'],
            'effort' => 'xhigh',
            'max_tokens' => 48000,
            'research' => true,
            'rewrites' => 2,
            'originality_threshold' => 80,
            'require_responsible_gambling' => true,
            // Оценка: сколько токенов примерно уйдёт на input/output
            'estimated_tokens' => [
                'input' => 2000,   // пример: 2k input tokens
                'output' => 12000, // пример: 12k output tokens
            ],
        ],

        'v6' => [
            'label' => 'V6 · Editorial + Brief',
            'speed' => 'Макс',
            'summary' => 'Editorial на основе брифа. Таблица сравнения первой, мини-таблица на каждый бренд, реальные сроки выплат, честный тираж лицензий, секция red flags. Стиль независимого эксперта. Аудит выполняется вручную редактором; автоматические перезапуски не применяются.',
            'stages' => ['research', 'brief', 'article', 'audit'],
            'effort' => 'xhigh',
            'max_tokens' => 48000,
            'research' => true,
            'editorial' => true,
            'require_responsible_gambling' => true,
            // Оценка: сколько токенов примерно уйдёт на input/output
            'estimated_tokens' => [
                'input' => 2000,   // пример: 2k input tokens
                'output' => 12000, // пример: 12k output tokens
            ],
        ],

        'v7' => [
            'label' => 'V7 · Моно-статья',
            'speed' => 'Долго',
            'summary' => 'Только для text_mono. Пятиэтапный конвейер: анализ сущностей, конкуренты, мастер-план, статья, аудит. Аудит предназначен для ручной проверки редактором; автоматические перезапуски не настроены.',
            'stages' => ['entities', 'research', 'masterplan', 'article', 'audit'],
            'effort' => 'xhigh',
            'max_tokens' => 64000,
            'research' => true,
            'mono' => true,
            // Оценка: сколько токенов примерно уйдёт на input/output
            'estimated_tokens' => [
                'input' => 2000,   // пример: 2k input tokens
                'output' => 12000, // пример: 12k output tokens
            ],
        ],
    ],

    'models' => [
        'opus-5' => [
            'label' => 'Opus 5',
            'id' => 'opus-5',
            'hint' => 'High quality, long context',
            'default' => true,
            'enabled' => true,
            'pricing' => [
                'input' => 0.00001,
                'output' => 0.00002,
                'cache_read' => 0.000001,
                'cache_write' => 0.000001,
            ],
            'effort' => ['low', 'medium', 'high'],
            'thinking' => ['none', 'short', 'long'],
            'web_tools' => 'full', // 'full' | 'limited' | 'none'
            'max_output_tokens' => 64000,
            'context_tokens' => 131072,
        ],
        'sonnet-5' => [
            'label' => 'Sonnet 5',
            'id' => 'sonnet-5',
            'hint' => 'Balanced cost/quality',
            'default' => false,
            'enabled' => true,
            'pricing' => [
                'input' => 0.000008,
                'output' => 0.000015,
                'cache_read' => 0.000001,
                'cache_write' => 0.000001,
            ],
            'effort' => ['low', 'medium'],
            'thinking' => ['none', 'short'],
            'web_tools' => 'limited',
            'max_output_tokens' => 32000,
            'context_tokens' => 65536,
        ],
        'haiku-4-5' => [
            'label' => 'Haiku 4-5',
            'id' => 'haiku-4-5',
            'hint' => 'Cheap, short context',
            'default' => false,
            'enabled' => true,
            'pricing' => [
                'input' => 0.000005,
                'output' => 0.00001,
                'cache_read' => 0.0000005,
                'cache_write' => 0.0000005,
            ],
            'effort' => ['low'],
            'thinking' => ['none'],
            'web_tools' => 'none',
            'max_output_tokens' => 8000,
            'context_tokens' => 16384,
        ],
    ],
];
