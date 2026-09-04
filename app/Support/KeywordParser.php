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
    private static function splitLine_old(string $line): array
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

    private static function splitLine(string $line): array
    {
        $line = trim($line, " \t\n\r\0\x0B");

        // 1) Если есть табуляция — строго табличная строка:
        // колонка 1 = ключ, колонка 2 = кандидат на частотность, остальные игнорируем.
        if (strpos($line, "\t") !== false) {
            $cols = preg_split('/\t+/', $line);
            $keyword = trim($cols[0] ?? '');
            $candidate = isset($cols[1]) ? trim($cols[1]) : '';

            $digits = self::normalizeFrequencyCandidate($candidate);
            if ($keyword !== '' && $digits !== null) {
                return [$keyword, (int)$digits];
            }

            return [$keyword, null];
        }

        // 2) Без табов — пытаемся найти число в конце строки, но только в строгом формате
        // Разделители между ключом и числом могут быть пробелы, запятые, ; или |

        if (preg_match('/^(.*?)[\s,;|]+(\d{1,3}(?:[ \x{00A0}\.]\d{3})*|\d+)$/u', $line, $m) === 1) {
            $keyword = trim($m[1]);
            $candidate = $m[2];

            $digits = self::normalizeFrequencyCandidate($candidate);
            if ($keyword !== '' && $digits !== null) {
                return [$keyword, (int)$digits];
            }
        }

        // Ничего однозначного — возвращаем ключ и null
        return [trim($line, " \t,;|"), null];
    }

    /**
     * Нормализует кандидат на частотность.
     * Возвращает строку с цифрами (без разделителей) или null если невалидно/неоднозначно.
     */
    private static function normalizeFrequencyCandidate(string $candidate): ?string
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return null;
        }

        // 1) Формат тысяч: 1-3 цифры, затем группы по 3 цифры, разделённые пробелом, NBSP или точкой
        if (preg_match('/^\d{1,3}(?:[ \x{00A0}\.]\d{3})*$/u', $candidate) === 1) {
            // Убираем пробелы, NBSP и точки (точки здесь считаем разделителем тысяч)
            $digits = preg_replace('/[ \x{00A0}\.]/u', '', $candidate);
            if ($digits !== '' && preg_match('/^\d+$/', $digits) === 1) {
                return $digits;
            }
            return null;
        }

        // 2) Простой целый без разделителей
        if (preg_match('/^\d+$/u', $candidate) === 1) {
            return $candidate;
        }

        // 3) Всё остальное (включая дробные с точкой) — отвергаем
        return null;
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
