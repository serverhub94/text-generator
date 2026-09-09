{{--
  Стили встроены намеренно: на сервере не нужен ни npm, ни сборка ассетов —
  выкладка сводится к git pull + composer install. Инструмент внутренний и
  маленький, отдельный фронтенд-пайплайн здесь стоил бы дороже, чем даёт.
--}}
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Генератор текстов')</title>
    <style>
        :root {
            --bg: #0f1218;
            --panel: #161b24;
            --panel-2: #1b2230;
            --line: #262f3d;
            --text: #e6eaf2;
            --muted: #8b97ab;
            --accent: #5b8cff;
            --ok: #34d399;
            --warn: #fbbf24;
            --err: #f87171;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 15px/1.55 ui-sans-serif, system-ui, "Segoe UI", Roboto, Arial, sans-serif;
        }

        a { color: var(--accent); }

        .wrap { max-width: 1080px; margin: 0 auto; padding: 32px 20px 64px; }

        header.top {
            display: flex; align-items: baseline; gap: 6px;
            flex-wrap: wrap; margin-bottom: 10px;
        }
        header.top h1 { font-size: 22px; margin: 0; letter-spacing: -.01em; }
        header.top .sub { color: var(--muted); font-size: 13px; }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
        }

        .grid { display: grid; gap: 14px; }
        .grid.cols-2 { grid-template-columns: 1fr 1fr; }
        .grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
        @media (max-width: 720px) {
            .grid.cols-2, .grid.cols-3 { grid-template-columns: 1fr; }
        }

        label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        label .req { color: var(--accent); }

        input[type=text], textarea, select {
            width: 100%;
            background: var(--panel-2);
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--text);
            padding: 9px 11px;
            font: inherit;
        }
        textarea { resize: vertical; min-height: 90px; }
        input:focus, textarea:focus, select:focus {
            outline: none; border-color: var(--accent);
        }
        .hint { font-size: 12px; color: var(--muted); margin-top: 5px; }

        .modes { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); }
        .mode {
            position: relative;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px 13px;
            background: var(--panel-2);
            cursor: pointer;
            transition: border-color .12s, background .12s;
        }
        .mode:hover { border-color: #38455c; }
        .mode input { position: absolute; opacity: 0; }
        .mode:has(input:checked) { border-color: var(--accent); background: #1a2235; }
        .mode .name { font-weight: 600; font-size: 14px; display: flex; justify-content: space-between; gap: 8px; }
        .mode .speed { color: var(--muted); font-size: 11px; font-weight: 400; white-space: nowrap; }
        .mode .desc { color: var(--muted); font-size: 12px; margin-top: 6px; }

        button.primary {
            background: var(--accent); color: #fff; border: 0;
            border-radius: 8px; padding: 11px 20px;
            font: 600 15px/1 inherit; cursor: pointer;
        }
        button.primary:hover { background: #4a7bef; }
        button.primary:disabled { opacity: .5; cursor: not-allowed; }

        .errors {
            background: #2a1719; border: 1px solid #5b2b2f;
            color: #fca5a5; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px;
        }
        .errors ul { margin: 0; padding-left: 18px; }

        .budget { font-size: 12px; color: var(--muted); }
        .budget .bar {
            height: 4px; background: var(--line); border-radius: 2px;
            overflow: hidden; margin-top: 6px; max-width: 260px;
        }
        .budget .bar i { display: block; height: 100%; background: var(--ok); }
        .budget .bar i.warn { background: var(--warn); }
        .budget .bar i.err { background: var(--err); }

        .tabs { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 14px; border-bottom: 1px solid var(--line); }
        .tabs button {
            background: none; border: 0; border-bottom: 2px solid transparent;
            color: var(--muted); padding: 9px 13px; cursor: pointer; font: inherit;
        }
        .tabs button.active { color: var(--text); border-bottom-color: var(--accent); }

        pre.doc {
            white-space: pre-wrap; word-wrap: break-word;
            background: var(--panel-2); border: 1px solid var(--line);
            border-radius: 8px; padding: 18px; margin: 0;
            font: 13.5px/1.6 ui-monospace, "Cascadia Code", Consolas, monospace;
            max-height: 70vh; overflow: auto;
        }

        .meta { display: grid; gap: 8px; margin-bottom: 14px; }
        .meta .field {
            display: flex; gap: 12px; align-items: flex-start;
            background: var(--panel-2); border: 1px solid var(--line);
            border-radius: 8px; padding: 9px 12px;
        }
        .meta .field .k { color: var(--muted); font-size: 12px; width: 130px; flex: none; padding-top: 3px; }
        .meta .field .v { flex: 1; font-size: 14px; word-break: break-word; }
        .meta .field .v.empty { color: var(--muted); }

        /* Предпросмотр статьи. Песочница без скриптов: разметку писала модель,
           и открывать ей доступ к странице инструмента незачем. */
        iframe.preview {
            width: 100%; height: 70vh; background: #fff;
            border: 1px solid var(--line); border-radius: 8px; display: block;
        }
        .btn-sm.on { border-color: var(--accent); color: var(--accent); }

        .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .spacer { flex: 1; }

        .badge {
            display: inline-block; padding: 3px 9px; border-radius: 20px;
            font-size: 12px; border: 1px solid var(--line); color: var(--muted);
        }
        .badge.ok { color: var(--ok); border-color: #1f4d3d; }
        .badge.err { color: var(--err); border-color: #5b2b2f; }
        .badge.run { color: var(--accent); border-color: #2c3f6b; }

        .stages { list-style: none; padding: 0; margin: 18px 0 0; }
        .stages li {
            display: flex; align-items: center; gap: 10px;
            padding: 7px 0; color: var(--muted); font-size: 14px;
        }
        .stages li .dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--line); flex: none;
        }
        .stages li.done { color: var(--text); }
        .stages li.done .dot { background: var(--ok); }
        .stages li.active { color: var(--accent); }
        .stages li.active .dot { background: var(--accent); animation: pulse 1.2s infinite; }
        @keyframes pulse { 50% { opacity: .3; } }

        .btn-sm {
            display: inline-block; padding: 6px 12px; border-radius: 7px;
            border: 1px solid var(--line); color: var(--text);
            text-decoration: none; font-size: 13px; background: var(--panel-2);
        }
        .btn-sm:hover { border-color: #38455c; }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top" style="padding:8px 12px;">

        <!-- Первый ряд: заголовок слева, область справа (в одном ряду, на всю ширину) -->
        <div style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div style="display:flex;flex-direction:column;line-height:1;">
                <!-- CHANGES: уменьшил размер шрифта и margin для экономии места -->
                <h1 style="margin:0;font-size:1.125rem;font-weight:600;line-height:1;">
                    <a href="{{ route('runs.create') }}" style="color:inherit;text-decoration:none">Генератор текстов</a>
                </h1>
                <span style="font-size:0.75rem;color:#6b7280;display:block;margin-top:2px;">iGaming SEO · ресёрч → ТЗ → текст</span>
            </div>

            <!-- Правая часть шапки -->
            <div style="margin-left:8px;display:flex;align-items:center;">
                @yield('header-right')
            </div>
        </div>

        <!-- CHANGES: навигация всегда с новой строки; уменьшены внешние отступы -->
        <nav aria-label="Главная навигация" style="margin-top:6px;margin-bottom:4px;">
            <a href="{{ route('runs.create') }}" style="color:#2563eb;text-decoration:none;margin-right:12px;font-size:0.95rem;line-height:1.1;">Главная</a>
            <a href="{{ route('runs.index') }}" style="color:#2563eb;text-decoration:none;font-size:0.95rem;line-height:1.1;">История</a>
        </nav>

    </header>




    @yield('content')
</div>
@stack('scripts')
</body>
</html>
