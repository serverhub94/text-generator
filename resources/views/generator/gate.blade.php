@extends('layout')

@section('title', 'Код доступа')

@section('content')
    <div class="card" style="max-width:420px">
        <form method="post">
            @csrf
            <label for="access_code">Код доступа</label>
            <input type="text" id="access_code" name="access_code" autofocus autocomplete="off">
            @if ($failed)
                <div class="hint" style="color:var(--err)">Код не подошёл.</div>
            @endif
            <div style="margin-top:14px">
                <button type="submit" class="primary">Войти</button>
            </div>
        </form>
    </div>
@endsection
