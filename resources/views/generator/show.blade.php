@extends('layout')

@section('title', $run->input['target_query'] ?? 'Прогон')

@section('header-right')
    <span class="badge {{ $run->status === 'done' ? 'ok' : ($run->status === 'failed' ? 'err' : 'run') }}"
          id="status-badge">
        {{ ['queued' => 'В очереди', 'running' => 'Генерация', 'done' => 'Готово', 'failed' => 'Ошибка'][$run->status] }}
    </span>
    {{-- Предупреждения о частичных результатах (например, упёрлось в max_tokens) --}}
    @if(!empty($run->warnings))
        <div class="alert alert-warning mb-3" role="alert">
            <h5 class="mb-2">Предупреждение</h5>
            <p class="mb-2">Во время генерации были зафиксированы предупреждения. Результаты предыдущих стадий сохранены частично.</p>
            <ul class="mb-0">
                @foreach($run->warnings as $w)
                    <li>
                        <strong>{{ $w['stage'] ?? 'stage' }}</strong>:
                        @if(($w['reason'] ?? '') === 'empty_article')
                            Пустая статья: финальная разметка оказалась пустой и не будет показана или скачана.
                        @else
                            {{ $w['message'] ?? ($w['reason'] ?? 'warning') }}
                        @endif
                        @if(!empty($w['time']))
                            <small class="text-muted">— {{ $w['time'] }}</small>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

@endsection

@section('content')

    <div class="card">
        <div class="row">
            <strong>{{ $run->input['target_query'] }}</strong>
            <span class="badge">{{ strtoupper($run->input['geo']) }} · {{ $run->input['language'] }}</span>
            <span class="badge">{{ $mode['label'] }}</span>
            <div class="spacer"></div>
            @if ($run->cost_usd > 0)
                <span class="hint" style="margin:0">≈ {{ number_format($run->cost_usd, 2) }} USD</span>
            @endif
        </div>

        @unless ($run->isFinished())
            {{-- Стадии режима известны заранее — показываем весь путь, а не только текущий шаг. --}}
            <ul class="stages" id="stages">
                @foreach ($mode['stages'] as $stage)
                    <li data-stage="{{ $stage }}"><span class="dot"></span><span class="label">{{ [
                        'entities' => 'Разбираю сущности',
                        'research' => 'Ресёрч: выдача, конкуренты, content gap',
                        'paa' => 'Собираю PAA-вопросы Google',
                        'masterplan' => 'Строю мастер-план страницы',
                        'brief' => 'Собираю ТЗ',
                        'article' => 'Пишу текст',
                        'audit' => 'Аудит текста',
                    ][$stage] ?? $stage }}</span></li>
                @endforeach
            </ul>
            <div class="hint" id="live-note" style="margin-top:14px">
                Можно закрыть вкладку — прогон идёт на сервере. Ссылка на эту страницу сохранит результат.
            </div>
        @endunless

        @if ($run->status === 'failed')
            <div class="errors" style="margin:16px 0 0">{{ $run->error }}</div>
        @endif
    </div>

    @if ($run->status === 'done')
        @php
            // Есть статья только если она не null и не пустая строка после trim
        $hasArticle = $run->article !== null && trim((string) $run->article) !== '';

        $tabs = array_filter([
        'article' => 'Текст',
        'brief' => 'ТЗ',
        'research' => 'Исследование',
        'audit' => 'Аудит',
        'paa' => 'PAA',
        'entities' => 'Сущности',
        'masterplan' => 'Мастер-план',
    ], fn ($_, $key) => $key === 'article'
        ? $hasArticle
        : $run->stageOutput($key) !== null, ARRAY_FILTER_USE_BOTH);

        @endphp

        <div class="card">
            <div class="tabs">
                @foreach ($tabs as $key => $label)
                    <button type="button" data-tab="{{ $key }}"
                            @class(['active' => $loop->first])>{{ $label }}</button>
                @endforeach
                <div class="spacer"></div>
            </div>

            @foreach ($tabs as $key => $label)
                @php $content = $key === 'article' ? $run->article : $run->stageOutput($key); @endphp
                <div data-panel="{{ $key }}" @style(['display: none' => ! $loop->first])>

                    {{-- Статья — это HTML-фрагмент для вставки между <body> и </body>.
                         Title, meta description и URL в разметку не входят: их
                         заполняют отдельными полями CMS, поэтому и показаны отдельно. --}}
                    @if ($key === 'article')
                        <div class="meta">
                            @foreach ([
                                'title' => 'Title',
                                'description' => 'Meta description',
                                'url' => 'URL',
                            ] as $field => $fieldLabel)
                                @php $value = $run->articleMeta($field); @endphp
                                <div class="field">
                                    <span class="k">{{ $fieldLabel }}</span>
                                    <span class="v @if ($value === '') empty @endif">{{ $value !== '' ? $value : 'нет данных' }}</span>
                                    @if ($value !== '')
                                        <button type="button" class="btn-sm" data-copy-text="{{ $value }}">Копировать</button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="row" style="margin-bottom:10px">
                        <a class="btn-sm" href="{{ route('runs.download', [$run, $key]) }}">
                            Скачать .{{ $key === 'article' ? 'html' : 'md' }}
                        </a>
                        <button type="button" class="btn-sm" data-copy="{{ $key }}">
                            {{ $key === 'article' ? 'Скопировать HTML' : 'Скопировать' }}
                        </button>
                        @if ($key === 'article')
                            <button type="button" class="btn-sm" data-preview>Предпросмотр</button>
                        @endif
                    </div>

                    <pre class="doc" id="doc-{{ $key }}">{{ $content }}</pre>

                    @if ($key === 'article')
                        @php
                            // Обвязка предпросмотра, а не деливеринг: минимальные
                            // стили, чтобы таблицы читались на белом фоне.
                            $preview = '<!doctype html><meta charset="utf-8"><style>'
                                .'body{font:16px/1.6 system-ui,sans-serif;color:#111;'
                                .'padding:24px;max-width:760px;margin:0 auto}'
                                .'table{border-collapse:collapse;width:100%;margin:16px 0}'
                                .'th,td{border:1px solid #ccc;padding:7px 10px;text-align:left}'
                                .'</style>'.$run->article;
                        @endphp
                        <iframe class="preview" id="preview-article" hidden
                                sandbox srcdoc="{{ $preview }}"></iframe>

                        @if ($run->articleMeta('notes') !== '')
                            <div style="margin-top:14px">
                                <label>Служебное — не публиковать</label>
                                <pre class="doc" style="max-height:none">{{ $run->articleMeta('notes') }}</pre>
                            </div>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>

        @if ($run->usage)
            <div class="card">
                <div class="hint" style="margin:0">
                    Токены: {{ number_format($run->usage['input']) }} вход ·
                    {{ number_format($run->usage['output']) }} выход ·
                    {{ number_format($run->usage['cache_read']) }} из кеша ·
                    поисковых запросов: {{ $run->usage['searches'] }} ·
                    итого ≈ {{ number_format($run->cost_usd, 2) }} USD
                </div>
            </div>
        @endif

        <a class="btn-sm" href="{{ route('runs.create') }}">← Новый текст</a>
    @endif

@endsection

@push('scripts')
<script>
(function () {
    const tabs = document.querySelectorAll('[data-tab]');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');

            document.querySelectorAll('[data-panel]').forEach(function (panel) {
                panel.style.display = panel.dataset.panel === tab.dataset.tab ? '' : 'none';
            });
        });
    });

    async function copied(btn, text) {
        await navigator.clipboard.writeText(text);
        const original = btn.textContent;
        btn.textContent = 'Скопировано';
        setTimeout(() => { btn.textContent = original; }, 1500);
    }

    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            copied(btn, document.getElementById('doc-' + btn.dataset.copy).textContent);
        });
    });

    document.querySelectorAll('[data-copy-text]').forEach(function (btn) {
        btn.addEventListener('click', function () { copied(btn, btn.dataset.copyText); });
    });

    // Предпросмотр и код — одно и то же содержимое, поэтому не две вкладки,
    // а переключатель: видно, что именно уедет в CMS.
    document.querySelectorAll('[data-preview]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const source = document.getElementById('doc-article');
            const frame = document.getElementById('preview-article');
            const showing = frame.hidden;

            frame.hidden = !showing;
            source.hidden = showing;
            btn.classList.toggle('on', showing);
            btn.textContent = showing ? 'Показать код' : 'Предпросмотр';
        });
    });

    @unless ($run->isFinished())
    // Опрос статуса. Стадии идут минутами, поэтому раз в три секунды —
    // чаще смысла нет, а нагрузку на сервер это экономит.
    const order = @json($mode['stages']);
    const stages = document.getElementById('stages');

    async function poll() {
        let data;

        try {
            const response = await fetch(@json(route('runs.status', $run)), {
                headers: { 'Accept': 'application/json' },
            });

            if (!response.ok) { return setTimeout(poll, 5000); }
            data = await response.json();
        } catch (e) {
            // Сетевой сбой не должен ронять опрос — пробуем ещё раз.
            return setTimeout(poll, 5000);
        }

        if (data.finished) { return location.reload(); }

        // Имя стадии может прийти как «rewrite:1» — берём часть до двоеточия.
        const current = (data.stage || '').split(':')[0];
        const index = order.indexOf(current);

        stages.querySelectorAll('li').forEach(function (li, i) {
            li.classList.toggle('done', index > -1 && i < index);
            li.classList.toggle('active', i === index);
        });

        if (data.stage && data.stage.includes(':')) {
            const active = stages.querySelector('li.active .label');
            if (active) { active.textContent = data.stage_label; }
        }

        setTimeout(poll, 3000);
    }

    poll();
    @endunless
})();
</script>
@endpush
