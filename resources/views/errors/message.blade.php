{{--
  Общая страница ошибки. Laravel показывает свои — они на английском и
  выпадают из панели, поэтому коды, до которых реально доходит человек
  (404, 419, 429, 500, 503), отрисованы в том же layout.
--}}
@extends('layout')

@section('title', $title)

@section('content')
    <div class="card" style="max-width:560px">
        <strong>{{ $title }}</strong>
        <div class="hint" style="margin-top:8px">{{ $text }}</div>
        <div style="margin-top:16px">
            <a class="btn-sm" href="{{ route('runs.create') }}">← К форме</a>
        </div>
    </div>
@endsection
