<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class ModesAuditSummaryTest extends TestCase
{
    public function testModesWithAuditButWithoutRewritesMustMentionManualAuditInSummary(): void
    {
        $modes = config('textgen.modes', []);

        $violations = [];

        foreach ($modes as $key => $mode) {
            $stages = $mode['stages'] ?? [];
            $hasAudit = in_array('audit', $stages, true);
            $hasRewrites = array_key_exists('rewrites', $mode);

            if ($hasAudit && ! $hasRewrites) {
                $summary = (string) ($mode['summary'] ?? '');
                $summaryLower = mb_strtolower($summary, 'UTF-8');

                // Ищем корень "ручн" чтобы покрыть "ручной", "вручную" и т.п.
                if (mb_strpos($summaryLower, 'ручн') === false) {
                    $violations[] = $key;
                }
            }
        }

        if (! empty($violations)) {
            $this->fail(
                'Следующие режимы содержат audit без rewrites, но в summary не указано, что аудит ручной: '
                . implode(', ', $violations)
            );
        }

        // Если нет нарушений — тест проходит
        $this->assertTrue(true);
    }
}
