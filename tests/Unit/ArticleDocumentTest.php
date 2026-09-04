<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ArticleDocument;
use Tests\TestCase;

class ArticleDocumentTest extends TestCase
{
    public function test_it_splits_meta_markup_and_editor_notes(): void
    {
        $doc = ArticleDocument::parse(<<<'TXT'
        ===META===
        title: Najlepsze kasyna online 2026
        description: Przeglad kasyn z licencja.
        url: /kasyna-online
        ===HTML===
        <div class="x"><h1>Kasyna</h1><p>Tekst</p></div>
        ===NOTES===
        - Что заполнить руками: сроки выплат
        TXT);

        $this->assertSame('Najlepsze kasyna online 2026', $doc->title);
        $this->assertSame('Przeglad kasyn z licencja.', $doc->description);
        $this->assertSame('/kasyna-online', $doc->url);
        $this->assertSame("<h1>Kasyna</h1>\n<p>Tekst</p>", $doc->html);
        $this->assertStringContainsString('сроки выплат', $doc->notes);
    }

    public function test_the_editor_notes_never_leak_into_the_markup(): void
    {
        $doc = ArticleDocument::parse(
            "===HTML===\n<p>Tekst</p>\n===NOTES===\nНе публиковать: черновая пометка"
        );

        $this->assertSame('<p>Tekst</p>', $doc->html);
        $this->assertStringNotContainsString('Не публиковать', $doc->html);
    }

    /**
     * Модель иногда отвечает без маркеров. Потерять статью хуже, чем остаться
     * без title, поэтому весь ответ считается разметкой.
     */
    public function test_an_answer_without_markers_is_still_an_article(): void
    {
        $doc = ArticleDocument::parse('<h1>Bez markerow</h1>');

        $this->assertSame('<h1>Bez markerow</h1>', $doc->html);
        $this->assertSame('', $doc->title);
    }

    public function test_it_accepts_the_markdown_habits_the_model_falls_back_to(): void
    {
        $doc = ArticleDocument::parse(<<<'TXT'
        ===META===
        **Title:** Kasyna
        **Meta description:** Opis strony
        ===HTML===
        <p>Tekst</p>
        TXT);

        $this->assertSame('Kasyna', $doc->title);
        $this->assertSame('Opis strony', $doc->description);
    }
}
