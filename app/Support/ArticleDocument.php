<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Разбирает выход стадии ARTICLE на три части: on-page мета, сам текст в HTML
 * и служебный блок для редактора.
 *
 * Разделение нужно потому, что деливеринг — именно HTML-фрагмент: то, что
 * вставляют между <body> и </body>. Title и meta description на страницу
 * попадают отдельными полями CMS, служебный блок не публикуется вовсе, и ни
 * тому, ни другому не место в разметке текста.
 *
 * Разбор нарочно снисходительный: если модель не поставила маркеры, всё
 * содержимое считается статьёй. Потерять текст хуже, чем остаться без мета.
 */
final readonly class ArticleDocument
{
    public function __construct(
        public string $html,
        public string $title = '',
        public string $description = '',
        public string $url = '',
        public string $notes = '',
    ) {}

    public static function parse(string $raw): self
    {
        $raw = self::stripOuterFence($raw);

        $meta = self::section($raw, 'META');
        $html = self::section($raw, 'HTML');
        $notes = self::section($raw, 'NOTES');

        // Маркеров нет — значит, весь ответ и есть статья.
        if ($html === null) {
            $html = $meta === null && $notes === null ? $raw : '';
        }

        $fields = self::fields($meta ?? '');

        return new self(
            html: HtmlFragment::clean($html),
            title: $fields['title'] ?? '',
            description: $fields['description'] ?? '',
            url: $fields['url'] ?? '',
            notes: trim($notes ?? ''),
        );
    }

    /**
     * Содержимое секции между маркерами, или null, если маркера нет.
     */
    private static function section(string $raw, string $name): ?string
    {
        $pattern = sprintf(
            '/^[ \t]*={2,}\s*%s\s*={2,}[ \t]*$(.*?)(?=^[ \t]*={2,}\s*[A-Z]+\s*={2,}[ \t]*$|\z)/msu',
            preg_quote($name, '/'),
        );

        if (preg_match($pattern, $raw, $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    /**
     * Строки вида «title: …» из блока META.
     *
     * @return array<string, string>
     */
    private static function fields(string $meta): array
    {
        $fields = [];

        foreach (preg_split('/\R/u', $meta) ?: [] as $line) {
            // Модель нет-нет да и вернётся к markdown-привычкам: «- **Title:** …».
            // Разметку вокруг ключа и значения снимаем, а не спотыкаемся о неё.
            if (preg_match('/^[\s\-*#]*(title|description|meta description|url)[\s*]*:\s*\**\s*(.+?)[\s*]*$/iu', $line, $m) !== 1) {
                continue;
            }

            $key = strtolower(trim($m[1]));
            $key = $key === 'meta description' ? 'description' : $key;

            // Первое вхождение выигрывает: повтор — обычно эхо шаблона.
            $fields[$key] ??= trim($m[2]);
        }

        return $fields;
    }

    /**
     * Снимает ```-обёртку вокруг всего ответа, если модель её поставила.
     */
    private static function stripOuterFence(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/^```[a-z]*\s*\n(.*)\n```$/su', $raw, $m) === 1) {
            return trim($m[1]);
        }

        return $raw;
    }
}
