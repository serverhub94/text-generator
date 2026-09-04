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

        if ($code === '') {
            return $next($request);
        }

        if ($request->session()->get('textgen_access') === $code) {
            return $next($request);
        }

        if ($request->isMethod('post') && $request->input('access_code') === $code) {
            $request->session()->put('textgen_access', $code);

            return redirect($request->fullUrl());
        }

        return response()->view('generator.gate', [
            'failed' => $request->isMethod('post'),
        ], 401);
    }
}
