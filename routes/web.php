<?php

use App\Http\Controllers\GeneratorController;
use App\Http\Middleware\AccessCode;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

// Маршрут для обработки ввода кода доступа
Route::post('/access', function (Request $request) {
    $configured = (string) config('textgen.limits.access_code', '');

    // Если код не настроен — просто редиректим на intended
    if ($configured === '') {
        return redirect()->intended('/');
    }

    $provided = (string) $request->input('access_code', '');

    // Сравнение через hash_equals для устойчивости к тайминг-атакам
    if ($provided !== '' && hash_equals($configured, $provided)) {
        // Помечаем сессию как авторизованную
        $request->session()->put('textgen_access', $configured);

        // Редиректим на сохранённый intended URL (или на / по умолчанию)
        return redirect()->intended('/');
    }

    // При неудаче показываем форму с флагом failed
    return response()->view('generator.gate', [
        'failed' => true,
    ], 401);
})->name('access.post');

Route::middleware(AccessCode::class)->group(function () {
    Route::get('/', [GeneratorController::class, 'index'])->name('runs.create');

    // Лимиты стоят только на запуске: он единственный тратит деньги.
    Route::post('/', [GeneratorController::class, 'store'])
        ->middleware(['throttle:textgen-hour', 'throttle:textgen-day'])
        ->name('runs.store');

    Route::get('/run/{run}', [GeneratorController::class, 'show'])->name('runs.show');

    // Опрос статуса идёт раз в пару секунд — свой лимит, посвободнее.
    Route::get('/run/{run}/status', [GeneratorController::class, 'status'])
        ->middleware('throttle:120,1')
        ->name('runs.status');

    Route::get('/run/{run}/download/{part}', [GeneratorController::class, 'download'])
        ->whereIn('part', ['article', 'research', 'brief', 'audit', 'paa', 'entities', 'masterplan'])
        ->name('runs.download');
});
