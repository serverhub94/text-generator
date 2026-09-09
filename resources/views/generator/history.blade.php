{{-- resources/views/generator/history.blade.php --}}
@extends('layout')

@section('content')
    <div class="container card">
        <h1>История генераций</h1>

        {{-- Фильтры --}}
        <form method="get" class="mb-3">
            <div class="row g-2">
                <div class="col-md-2">
                    <select name="status" class="form-control">
                        <option value="">Все статусы</option>
                        @foreach($statuses as $k => $v)
                            <option value="{{ $k }}" {{ request('status') === (string)$k ? 'selected' : '' }}>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="mode" class="form-control">
                        <option value="">Все режимы</option>
                        @foreach($modes as $k => $m)
                            <option value="{{ $k }}" {{ request('mode') === $k ? 'selected' : '' }}>{{ $m['label'] ?? $k }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="model" class="form-control">
                        <option value="">Все модели</option>
                        @foreach($models as $k => $m)
                            <option value="{{ $k }}" {{ request('model') === $k ? 'selected' : '' }}>{{ $m['label'] ?? $k }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <input type="search" name="q" class="form-control" placeholder="Поиск по запросу" value="{{ request('q') }}">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100">Фильтровать</button>
                </div>
            </div>
        </form>

        @if($runs->count() === 0)
            <div class="alert alert-info">Нет записей истории.</div>
        @else
            <table class="table table-sm table-striped">
                <thead>
                <tr>
                    <th>Дата</th>
                    <th>Целевой запрос</th>
                    <th>GEO</th>
                    <th>Язык</th>
                    <th>Режим</th>
                    <th>Модель</th>
                    <th>Статус</th>
                    <th>Стоимость (USD)</th>
                    <th>Длительность</th>
                    <th>Ссылка</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>

                @foreach($runs as $run)
                    <tr>
                        <td>{{ $run->created_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $run->target_query ?? ($run->input['target_query'] ?? '') }}</td>
                        <td>{{ strtoupper($run->input['geo'] ?? '') }}</td>
                        <td>{{ $run->input['language'] ?? '' }}</td>
                        <td>{{ $run->mode }}</td>
                        <td>{{ $run->model }}</td>
                        <td>{{ $run->status }}</td>
                        <td>{{ number_format($run->cost_usd ?? 0, 4) }}</td>
                        <td>
                            @if($run->started_at && $run->finished_at)
                                {{ $run->finished_at->diffForHumans($run->started_at, true) }}
                            @elseif($run->started_at)
                                В процессе
                            @else
                                -
                            @endif
                        </td>
                        <td><a href="{{ route('runs.show', $run) }}" target="_blank">Открыть</a></td>
                        <td class="text-end">
                            {{-- Кнопка повторить: ведёт на форму с ?from={uuid} --}}
                            <a href="{{ route('runs.show', ['run' => $run->uuid]) }}" class="btn btn-sm btn-outline-secondary">Повторить</a>

                            {{-- Удаление: форма DELETE --}}
                            <form action="{{ route('runs.destroy', $run) }}" method="post" style="display:inline" onsubmit="return confirm('Удалить прогон?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger">Удалить</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            {{ $runs->links() }}
        @endif
    </div>
@endsection
