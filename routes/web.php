<?php

use App\Http\Controllers\GeneratorController;
use App\Http\Middleware\AccessCode;
use Illuminate\Support\Facades\Route;

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
