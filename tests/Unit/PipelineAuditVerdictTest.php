<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\Pipeline;

final class PipelineAuditVerdictTest extends TestCase
{
    public function testParseAuditVerdictIgnoresEchoAndRecognizesFailed(): void
    {
        $audit = <<<TXT
# Stage: AUDIT

## Вердикт
ПРОШЁЛ | ПРОШЁЛ С ЗАМЕЧАНИЯМИ | НЕ ПРОШЁЛ

НЕ ПРОШЁЛ

## Критично
...
TXT;

        // В этом тексте первая непустая строка после заголовка — перечисление, но следующая — реальный вердикт.
        $this->assertSame('failed', Pipeline::parseAuditVerdict($audit));
    }

    public function testParseAuditVerdictReturnsNullWhenOnlyEchoPresent(): void
    {
        $audit = <<<TXT
## Вердикт
ПРОШЁЛ | ПРОШЁЛ С ЗАМЕЧАНИЯМИ | НЕ ПРОШЁЛ

## Критично
...
TXT;
        $this->assertNull(Pipeline::parseAuditVerdict($audit));
    }

    public function testParseAuditVerdictRecognizesPassedAndPassedWithRemarks(): void
    {
        $this->assertSame('passed', Pipeline::parseAuditVerdict("## Вердикт\nПРОШЁЛ\n"));
        $this->assertSame('passed_with_remarks', Pipeline::parseAuditVerdict("## Вердикт\nПРОШЁЛ С ЗАМЕЧАНИЯМИ\n"));
    }
}
