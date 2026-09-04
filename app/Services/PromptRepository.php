<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Загружает шаблоны промптов из resources/prompts и подставляет переменные.
 *
 * Промпты лежат отдельными markdown-файлами намеренно: редактор правит
 * формулировки, не трогая PHP. Плейсхолдер — {{ name }}.
 */
final class PromptRepository
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(private readonly string $basePath) {}

    /**
     * Общие правила. Это стабильный префикс — он же кешируется на стороне API,
     * поэтому переменные в нём подставляются, но состав текста не меняется от
     * запроса к запросу в рамках одного прогона.
     *
     * @param array<string, string> $vars
     */
    public function rules(array $vars): string
    {
        return $this->render('rules', $vars);
    }

    /**
     * @param array<string, string> $vars
     */
    public function stage(string $stage, array $vars): string
    {
        return $this->render("stages/{$stage}", $vars);
    }

    /**
     * @param array<string, string> $vars
     */
    public function mode(string $mode, array $vars): string
    {
        return $this->render("modes/{$mode}", $vars);
    }

    /**
     * @param array<string, string> $vars
     */
    private function render(string $name, array $vars): string
    {
        $template = $this->load($name);

        $replacements = [];

        foreach ($vars as $key => $value) {
            // Допускаем и {{ key }}, и {{key}} — редакторы пишут по-разному.
            $replacements['{{ '.$key.' }}'] = $value;
            $replacements['{{'.$key.'}}'] = $value;
        }

        $rendered = strtr($template, $replacements);

        // Незаполненный плейсхолдер — это опечатка в шаблоне, а не «пустое
        // значение»: молча оставить {{ ... }} в промпте хуже, чем упасть.
        if (preg_match('/\{\{\s*([a-z_]+)\s*\}\}/i', $rendered, $m) === 1) {
            throw new RuntimeException(
                "Промпт {$name}: не подставлена переменная {{ {$m[1]} }}."
            );
        }

        return $rendered;
    }

    private function load(string $name): string
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $path = $this->basePath.'/'.$name.'.md';

        if (! is_file($path)) {
            throw new RuntimeException("Промпт не найден: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Не удалось прочитать промпт: {$path}");
        }

        return $this->cache[$name] = $contents;
    }
}
