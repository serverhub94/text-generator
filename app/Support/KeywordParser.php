<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Разбирает вставленную из Ahrefs семантику в структуру «ключ + частотность».
 *
 * Принимает то, что реально получается при копировании из Ahrefs или Google
 * Docs: ключ, произвольный разделитель (табы, пробелы, запятая, точка с
 * запятой, вертикальная черта) и число. Частотность может отсутствовать —
 * тогда она остаётся null и ниже по конвейеру превращается в «нет данных».
 * Выдумывать её нельзя, поэтому нулём или средним по списку не заполняем.
 */
final class KeywordParser
{
    /**
     * @return list<array{keyword: string, volume: int|null}>
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $rows = [];
        $seen = [];

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Пропускаем шапку выгрузки, если её скопировали вместе с данными.
            if (preg_match('/^(keyword|ключ|query|запрос)\b/iu', $line) === 1
                && preg_match('/\d/', $line) !== 1) {
                continue;
            }

            [$keyword, $volume] = self::splitLine($line);

            if ($keyword === '') {
                continue;
            }

            // Ahrefs отдаёт дубли при пересечении групп — держим первое вхождение.
            $key = mb_strtolower($keyword);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = ['keyword' => $keyword, 'volume' => $volume];
        }

        return $rows;
    }

    /**
     * Отделяет частотность от ключа.
     *
     * @return array{0: string, 1: int|null}
     */
    private static function splitLine(string $line): array
    {
        // Число в конце строки — частотность. Разделителем может быть что
        // угодно, включая длинную цепочку табов из Google Docs.
        if (preg_match('/^(.*?)[\s,;|]+([\d][\d\s\x{00A0},.]*)$/u', $line, $m) === 1) {
            $keyword = trim($m[1]);
            $digits = preg_replace('/[^\d]/u', '', $m[2]) ?? '';

            if ($keyword !== '' && $digits !== '') {
                return [$keyword, (int) $digits];
            }
        }

        // Частотности нет — это допустимо, ключ идёт с «нет данных».
        return [trim($line, " \t,;|"), null];
    }

    /**
     * Готовит блок для промпта: ключ и частотность строго как их дал человек.
     *
     * @param list<array{keyword: string, volume: int|null}> $keywords
     */
    public static function toPromptTable(array $keywords): string
    {
        if ($keywords === []) {
            return 'Семантика не предоставлена.';
        }

        $lines = ['| Ключ | Частотность |', '| --- | --- |'];

        foreach ($keywords as $row) {
            $volume = $row['volume'] === null
                ? 'нет данных'
                : (string) $row['volume'];

            $lines[] = sprintf('| %s | %s |', $row['keyword'], $volume);
        }

        return implode("\n", $lines);
    }
}
