<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Необязательный код доступа.
 *
 * По умолчанию выключен — сабдомен открыт, как и просили. Если однажды
 * окажется, что ключ жгут чужие, достаточно вписать TEXTGEN_ACCESS_CODE
 * в .env: это не полноценная авторизация, а одна дверь на всю команду.
 */
class AccessCode
{

    public function handle(Request $request, Closure $next): Response
    {
        $code = (string) config('textgen.limits.access_code');

        // Если код не задан — доступ открыт
        if ($code === '') {
            return $next($request);
        }

        // Если уже в сессии — пропускаем
        if ($request->session()->get('textgen_access') === $code) {
            return $next($request);
        }

        // Сохраняем целевой URL, чтобы после входа вернуть пользователя
        $request->session()->put('url.intended', $request->fullUrl());

        // Показываем форму ввода кода. Форма отправляет POST на /access.
        return response()->view('generator.gate', [
            'failed' => false,
        ], 401);
    }
}
