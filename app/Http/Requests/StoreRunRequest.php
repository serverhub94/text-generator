<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_query' => ['required', 'string', 'max:200'],

            // Двухбуквенный код — тот же формат, в котором команда уже пишет
            // GEO в конвейере (PL / CA / UK).
            'geo' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],

            // Формат lang-LANG: en-GB, es-AR, pl-PL.
            'language' => ['required', 'string', 'regex:/^[a-z]{2}(-[A-Za-z]{2,4})?$/'],

            'domain' => ['nullable', 'string', 'max:255'],
            'page_type' => ['nullable', 'string', 'max:120'],
            'site_type' => ['nullable', 'string', 'max:120'],
            'page_goal' => ['nullable', 'string', 'max:500'],

            // Больше десяти целевых запросов размывают фокус анализа —
            // это правило из процесса команды, не техническое ограничение.
            'target_queries' => ['nullable', 'string', 'max:2000'],

            'keywords' => ['nullable', 'string', 'max:60000'],
            'competitors' => ['nullable', 'string', 'max:8000'],
            'notes' => ['nullable', 'string', 'max:8000'],
           // 'ai_model' => ['required', 'string', 'max:500'],
            'ai_model' => ['required', 'string', 'in:claude,gemini'],

            'mode' => ['required', Rule::in(array_keys(config('textgen.modes')))],
        ];
    }

    public function messages(): array
    {
        return [
            'geo.regex' => 'GEO — две буквы, например PL, CA или UK.',
            'language.regex' => 'Язык в формате lang-LANG, например en-GB или es-AR.',
            'target_query.required' => 'Укажите ключевой запрос, под который делаем страницу.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $queries = array_filter(
                array_map('trim', preg_split('/\R/u', (string) $this->input('target_queries')) ?: []),
            );

            if (count($queries) > 10) {
                $validator->errors()->add(
                    'target_queries',
                    'Не больше 10 целевых запросов — иначе размывается фокус анализа. '
                    .'Остальные ключи вставьте в поле семантики.',
                );
            }
        });
    }
}
