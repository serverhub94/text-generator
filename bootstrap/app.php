<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
// Внимание !!!! решает проблему ошибки 500 подключает понятную ошибку дебагер Spatie
 /*   ->withExceptions(function (Exceptions $exceptions) {
        // Патч для дебаггера: ловим ошибки Blade на Windows до падения сервера в 500
        if (config('app.debug') && !request()->expectsJson()) {
            $exceptions->reportable(function (\Throwable $e) {
                while (ob_get_level() > 0) ob_end_clean();

                if (class_exists(\Spatie\LaravelIgnition\IgnitionServiceProvider::class)) {
                    (new \Spatie\Ignition\Ignition())->handleException($e->getPrevious() ?? $e);
                    exit;
                }
            });
        }
    })*/
    ->create();

