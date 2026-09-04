@extends('layout')

@section('title', 'Генератор текстов')

@section('header-right')
    @unless($budget->isDisabled())
        @php
            $fraction = $budget->fraction();
            $class = $fraction >= 1 ? 'err' : ($fraction >= 0.8 ? 'warn' : '');
        @endphp
        <div class="budget">
            Бюджет месяца: {{ number_format($budget->spentThisMonth(), 2) }} /
            {{ number_format($budget->budget(), 2) }} USD
            <span class="bar"><i class="{{ $class }}" style="width: {{ round($fraction * 100) }}%"></i></span>
        </div>
    @endunless
@endsection

@section('content')

    @if ($errors->any())
        <div class="errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ route('runs.store') }}">
        @csrf

        <div class="card">
            <div class="grid cols-3">
                <div>
                    <label for="target_query">Ключевой запрос <span class="req">*</span></label>
                    <input type="text" id="target_query" name="target_query"
                           value="{{ old('target_query') }}" placeholder="mostbet login" required>
                    <div class="hint">Главный ключ страницы.</div>
                </div>
                <div>
                    <label for="geo">GEO <span class="req">*</span></label>
                    <input type="text" id="geo" name="geo" value="{{ old('geo') }}"
                           placeholder="PL" maxlength="2" required>
                    <div class="hint">Две буквы: PL, CA, UK. Задаёт страну выдачи.</div>
                </div>
                <div>
                    <label for="language">Язык <span class="req">*</span></label>
                    <input type="text" id="language" name="language" value="{{ old('language') }}"
                           placeholder="pl-PL" required>
                    <div class="hint">Формат lang-LANG: en-GB, es-AR, pl-PL.</div>
                </div>
            </div>

            <div class="grid cols-3" style="margin-top:14px">
                <div>
                    <label for="domain">Ваш домен</label>
                    <input type="text" id="domain" name="domain" value="{{ old('domain') }}"
                           placeholder="example.com">
                    <div class="hint">Для content gap и реалити-чека.</div>
                </div>
                <div>
                    <label for="page_type">Тип страницы</label>
                    <input type="text" id="page_type" name="page_type"
                           value="{{ old('page_type', 'обзор') }}" placeholder="главная / обзор / лендинг">
                </div>
                <div>
                    <label for="site_type">Тип сайта</label>
                    <input type="text" id="site_type" name="site_type"
                           value="{{ old('site_type', 'партнёрский казино-сайт') }}">
                </div>
            </div>

            <div style="margin-top:14px">
                <label for="page_goal">Цель страницы</label>
                <input type="text" id="page_goal" name="page_goal"
                       value="{{ old('page_goal', 'стимулировать регистрации и депозиты, дать информацию новым и опытным игрокам') }}">
            </div>
        </div>

        <div class="card">
            <div class="grid cols-2">
                <div>
                    <label for="target_queries">Целевые запросы для анализа</label>
                    <textarea id="target_queries" name="target_queries" rows="6"
                              placeholder="mostbet login&#10;mostbet login page&#10;mostbet account login&#10;mostbet sign in">{{ old('target_queries') }}</textarea>
                    <div class="hint">По одному на строку, <strong>не больше 10</strong> — иначе размывается фокус анализа.</div>
                </div>
                <div>
                    <label for="competitors">Конкуренты на анализ</label>
                    <textarea id="competitors" name="competitors" rows="6"
                              placeholder="competitor1.com/page&#10;competitor2.com/page">{{ old('competitors') }}</textarea>
                    <div class="hint">Если оставить пустым, конкуренты берутся из живой выдачи по GEO.</div>
                </div>
            </div>

            <div style="margin-top:14px">
                <label for="keywords">Семантика с частотностью</label>
                <textarea id="keywords" name="keywords" rows="8"
                          placeholder="1win bet&#9;500&#10;1win apuestas&#9;300&#10;apuesta 1win&#9;100">{{ old('keywords') }}</textarea>
                <div class="hint">
                    Вставьте как есть из Ahrefs: ключ и число в одной строке.
                    Ключ без числа допустим — он пойдёт с пометкой «нет данных».
                    <strong>Это единственный источник цифр в системе</strong> — то, чего здесь нет, модель не выдумает.
                </div>
            </div>

            <div style="margin-top:14px">
                <label for="notes">Дополнительно от редактора</label>
                <textarea id="notes" name="notes" rows="3"
                          placeholder="Особые требования, брендбук, что упомянуть обязательно, чего избегать">{{ old('notes') }}</textarea>
            </div>
        </div>

        <div class="card">
            <label style="margin-bottom:12px">Режим генерации</label>
            <div class="modes">
                @foreach ($modes as $key => $mode)
                    <label class="mode">
                        <input type="radio" name="mode" value="{{ $key }}"
                               @checked(old('mode', 'v3') === $key)>
                        <span class="name">
                            {{ $mode['label'] }}
                            <span class="speed">{{ $mode['speed'] }}</span>
                        </span>
                        <span class="desc">{{ $mode['summary'] }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="row">
            <button type="submit" class="primary" @disabled($budget->exceeded())>
                Запустить генерацию
            </button>
            <span class="hint" style="margin:0">
                @if ($budget->exceeded())
                    Месячный потолок расходов исчерпан.
                @else
                    Полный цикл с ресёрчем идёт несколько минут — страница сама покажет прогресс.
                @endif
            </span>
        </div>
    </form>

@endsection
