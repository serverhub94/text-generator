<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\KeywordParser;
use PHPUnit\Framework\TestCase;

class KeywordParserTest extends TestCase
{
    public function test_it_parses_the_tab_separated_shape_ahrefs_actually_produces(): void
    {
        $raw = "1win bet\t\t\t\t500\n1win apuestas\t\t\t300";

        $this->assertSame([
            ['keyword' => '1win bet', 'volume' => 500],
            ['keyword' => '1win apuestas', 'volume' => 300],
        ], KeywordParser::parse($raw));
    }

    public function test_it_accepts_comma_semicolon_and_pipe_separators(): void
    {
        $rows = KeywordParser::parse("a bet, 500\nb bet; 300\nc bet | 200");

        $this->assertSame([500, 300, 200], array_column($rows, 'volume'));
    }

    public function test_a_keyword_without_a_figure_is_kept_with_no_volume(): void
    {
        $rows = KeywordParser::parse("apuestas sin registro");

        // Именно null, а не 0: ниже по конвейеру это станет «нет данных».
        // Подставить ноль означало бы выдумать цифру.
        $this->assertSame([['keyword' => 'apuestas sin registro', 'volume' => null]], $rows);
    }

    public function test_it_handles_thousands_separated_by_spaces(): void
    {
        $rows = KeywordParser::parse("1win deportes | 1 200");

        $this->assertSame(1200, $rows[0]['volume']);
    }

    public function test_a_leading_number_in_the_keyword_is_not_mistaken_for_a_figure(): void
    {
        $rows = KeywordParser::parse("1 win bet\t150");

        $this->assertSame('1 win bet', $rows[0]['keyword']);
        $this->assertSame(150, $rows[0]['volume']);
    }

    public function test_it_skips_a_copied_header_row(): void
    {
        $rows = KeywordParser::parse("Keyword\tVolume\n1win bet\t500");

        $this->assertCount(1, $rows);
        $this->assertSame('1win bet', $rows[0]['keyword']);
    }

    public function test_it_keeps_the_first_of_duplicated_keywords(): void
    {
        $rows = KeywordParser::parse("1win bet\t500\n1WIN BET\t999");

        $this->assertCount(1, $rows);
        $this->assertSame(500, $rows[0]['volume']);
    }

    public function test_the_prompt_table_marks_missing_figures_as_no_data(): void
    {
        $table = KeywordParser::toPromptTable(KeywordParser::parse("a\t500\nb"));

        $this->assertStringContainsString('| a | 500 |', $table);
        $this->assertStringContainsString('| b | нет данных |', $table);
    }

    public function test_empty_input_yields_no_rows(): void
    {
        $this->assertSame([], KeywordParser::parse(null));
        $this->assertSame([], KeywordParser::parse("   \n  "));
    }
}
