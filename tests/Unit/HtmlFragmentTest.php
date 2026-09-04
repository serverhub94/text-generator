<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HtmlFragment;
use Tests\TestCase;

/**
 * Деливеринг — фрагмент для вставки между <body> и </body>. Всё, что редактору
 * пришлось бы вычищать руками, обязано отваливаться здесь.
 */
class HtmlFragmentTest extends TestCase
{
    public function test_it_strips_every_presentation_attribute(): void
    {
        $html = HtmlFragment::clean(
            '<h2 class="title" id="h" style="color:red" data-x="1">Bonusy</h2>'
        );

        $this->assertSame('<h2>Bonusy</h2>', $html);
    }

    public function test_it_unwraps_layout_tags_but_keeps_their_text(): void
    {
        $html = HtmlFragment::clean('<div class="wrap"><p>Tekst <span>w srodku</span></p></div>');

        $this->assertSame('<p>Tekst w srodku</p>', $html);
    }

    public function test_it_drops_scripts_and_styles_with_their_contents(): void
    {
        $html = HtmlFragment::clean('<p>a</p><script>alert(1)</script><style>p{color:red}</style>');

        $this->assertSame('<p>a</p>', $html);
    }

    public function test_it_removes_the_document_wrapper(): void
    {
        $html = HtmlFragment::clean(
            '<!DOCTYPE html><html><head><title>SEO title</title></head><body><h1>H</h1></body></html>'
        );

        // Заголовок из <head> — не текст страницы, он не должен просочиться.
        $this->assertSame('<h1>H</h1>', $html);
    }

    public function test_it_keeps_links_and_table_spans(): void
    {
        $html = HtmlFragment::clean(
            '<table><tr><th colspan="2" class="x">Marka</th></tr>'
            .'<tr><td><a href="https://x.pl" target="_blank" rel="nofollow">Betsson</a></td><td>18+</td></tr></table>'
        );

        $this->assertStringContainsString('<th colspan="2">Marka</th>', $html);
        $this->assertStringContainsString('<a href="https://x.pl">Betsson</a>', $html);
        $this->assertStringNotContainsString('target', $html);
    }

    public function test_a_link_that_would_run_code_loses_its_tag_not_its_text(): void
    {
        $html = HtmlFragment::clean('<p><a href="javascript:alert(1)">zly link</a></p>');

        $this->assertSame('<p>zly link</p>', $html);
    }

    public function test_it_survives_the_model_wrapping_everything_in_a_code_fence(): void
    {
        $html = HtmlFragment::clean("```html\n<h1>Kasyna</h1>\n```");

        $this->assertSame('<h1>Kasyna</h1>', $html);
    }

    public function test_it_does_not_invent_markup_around_plain_text(): void
    {
        // Чистка не дописывает теги: если модель дала голый текст, значит
        // дала голый текст — это видно и в интерфейсе, и в аудите.
        $this->assertSame('просто текст', HtmlFragment::clean('просто текст'));
        $this->assertSame('', HtmlFragment::clean("   \n  "));
    }

    public function test_cyrillic_and_diacritics_survive_the_round_trip(): void
    {
        $html = HtmlFragment::clean('<p>Najlepsze kasyna — нет данных &amp; wiecej</p>');

        $this->assertSame('<p>Najlepsze kasyna — нет данных &amp; wiecej</p>', $html);
    }

    public function test_headings_and_blocks_come_back_one_per_line(): void
    {
        $html = HtmlFragment::clean('<h1>A</h1><p>B</p><ul><li>C</li></ul>');

        $this->assertSame("<h1>A</h1>\n<p>B</p>\n<ul>\n    <li>C</li>\n</ul>", $html);
    }
}
