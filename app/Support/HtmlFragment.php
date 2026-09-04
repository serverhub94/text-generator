<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Приводит HTML, который выдала модель, к тому виду, который просили: чистый
 * фрагмент для вставки внутрь <body> — без классов, стилей, id и обёрток.
 *
 * Модель почти всегда отдаёт нужное, но «почти» здесь недостаточно: один
 * class="wp-block-table" в выдаче — и правку делает руками редактор. Поэтому
 * разметка не проверяется, а пересобирается: разбираем в DOM, оставляем только
 * теги и атрибуты из белого списка, печатаем заново.
 *
 * Тег не из списка не удаляется, а разворачивается — <div>текст</div>
 * превращается в «текст». Удаляем с содержимым только то, чему в тексте статьи
 * места нет вовсе (script, style, head и прочая служебная обвязка).
 */
final class HtmlFragment
{
    /** Теги, которые разрешено оставить. Всё прочее разворачивается. */
    private const ALLOWED = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'ul', 'ol', 'li', 'blockquote',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'strong', 'em', 'a', 'br', 'hr',
    ];

    /** Теги, которые удаляются вместе с содержимым. */
    private const DROPPED = [
        'script', 'style', 'head', 'meta', 'link', 'title', 'noscript',
        'iframe', 'svg', 'form', 'input', 'button', 'template',
    ];

    /** Единственные атрибуты, которые переживают чистку. */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href'],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    /** Теги, которые печатаются с новой строки. Остальные — по месту. */
    private const BLOCK = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'ul', 'ol', 'li', 'blockquote',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'hr',
    ];

    private const VOID = ['br', 'hr'];

    /** Теги, внутри которых перенос строки менял бы отображение. */
    private const INLINE_ONLY = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'th', 'td', 'caption', 'li',
    ];

    public static function clean(string $html): string
    {
        $html = self::stripCodeFences($html);

        if (trim($html) === '') {
            return '';
        }

        // Текста без единого тега тоже достаточно: чистка не выдумывает
        // разметку, которой модель не дала.
        if (! str_contains($html, '<')) {
            return trim($html);
        }

        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);

        // Модель отдаёт фрагмент, а не документ — NOIMPLIED убирает html/body,
        // которые libxml иначе допишет за нас. Кодировку задаём инструкцией:
        // без неё libxml читает вход как latin-1 и ломает кириллицу.
        $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $out = '';

        foreach (iterator_to_array($document->childNodes) as $node) {
            if (self::isBlankText($node)) {
                continue;
            }

            $out .= self::render($node, 0);
        }

        // Пустые строки между блоками — след развёрнутых обёрток.
        $out = preg_replace("/\n{3,}/", "\n\n", $out) ?? $out;

        return trim($out);
    }

    /**
     * Модель любит завернуть ответ в ```html … ``` — снимаем обёртку, если она
     * охватывает весь фрагмент.
     */
    private static function stripCodeFences(string $html): string
    {
        $html = trim($html);

        if (preg_match('/^```[a-z]*\s*\n(.*)\n```$/su', $html, $m) === 1) {
            return trim($m[1]);
        }

        return $html;
    }

    private static function render(DOMNode $node, int $depth): string
    {
        if ($node instanceof DOMText) {
            return self::escape($node->wholeText);
        }

        if (! $node instanceof DOMElement) {
            // Комментарии, инструкции обработки, DTD — в статью не идут.
            return '';
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::DROPPED, true)) {
            return '';
        }

        $children = self::renderChildren($node, $tag, $depth);

        if (! in_array($tag, self::ALLOWED, true)) {
            // Обёртка: тег снимаем, содержимое сохраняем.
            return $children;
        }

        $attributes = self::attributes($node, $tag);

        // Ссылка без адреса — не ссылка: оставляем текст, снимаем тег.
        if ($tag === 'a' && $attributes === '') {
            return $children;
        }

        $open = '<'.$tag.$attributes.'>';

        if (in_array($tag, self::VOID, true)) {
            return self::isBlock($tag) ? "\n".self::indent($depth).$open : $open;
        }

        if (! self::isBlock($tag)) {
            return $open.$children.'</'.$tag.'>';
        }

        $indent = self::indent($depth);

        // Внутри заголовка или ячейки перенос строки — это лишний пробел
        // в готовом тексте, поэтому такие теги печатаем одной строкой.
        if (in_array($tag, self::INLINE_ONLY, true) || trim($children) === '') {
            return "\n".$indent.$open.trim($children).'</'.$tag.'>';
        }

        return "\n".$indent.$open.$children."\n".$indent.'</'.$tag.'>';
    }

    private static function renderChildren(DOMElement $node, string $tag, int $depth): string
    {
        // Развёрнутая обёртка не должна съезжать вправо: отступ считается по
        // разрешённым тегам, а не по исходной вложенности.
        $childDepth = in_array($tag, self::ALLOWED, true) && self::isBlock($tag)
            ? $depth + 1
            : $depth;

        // Переносы и отступы исходной разметки внутри контейнера — мусор:
        // в <ul> или <tr> текста между элементами не бывает.
        $dropsWhitespace = ! in_array($tag, self::INLINE_ONLY, true);

        $out = '';

        foreach ($node->childNodes as $child) {
            if ($dropsWhitespace && self::isBlankText($child)) {
                continue;
            }

            $out .= self::render($child, $childDepth);
        }

        return $out;
    }

    private static function isBlankText(DOMNode $node): bool
    {
        return $node instanceof DOMText && trim($node->wholeText) === '';
    }

    private static function attributes(DOMElement $node, string $tag): string
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
        $out = '';

        foreach ($allowed as $name) {
            $value = trim($node->getAttribute($name));

            if ($value === '') {
                continue;
            }

            // Ссылка на javascript: в тексте статьи означает только одно —
            // что-то пошло не так. Отдаём тег без атрибута.
            if ($name === 'href' && preg_match('/^\s*(javascript|data|vbscript):/i', $value) === 1) {
                continue;
            }

            $out .= sprintf(' %s="%s"', $name, htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return $out;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function isBlock(string $tag): bool
    {
        return in_array($tag, self::BLOCK, true);
    }

    private static function indent(int $depth): string
    {
        return str_repeat('    ', max(0, $depth));
    }
}
