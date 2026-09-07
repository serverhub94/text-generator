<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ClaudeService;
use App\Services\ClaudeResult;

final class ClaudeServiceToolErrorsTest extends TestCase
{
    private array $config = [
        'pricing' => [
            'input_per_mtok' => 5.0,
            'output_per_mtok' => 25.0,
            'cache_read_per_mtok' => 0.5,
            'cache_write_per_mtok' => 10.0,
        ],
    ];

    public function testParseMessageCountsToolResponsesAndErrors(): void
    {
        $textBlock = (object)['text' => 'text'];
        $errorBlock = (object)['tool' => 'web_search', 'error' => (object)['code' => 'max_uses_exceeded','message'=>'limit']];
        $resultBlock = (object)['tool' => 'web_search', 'results' => [(object)['title'=>'r','url'=>'u']]];
        $message = (object)['content' => [$textBlock, $errorBlock, $resultBlock], 'usage' => (object)['inputTokens'=>1,'outputTokens'=>2], 'stopReason' => 'stop'];

        $res = ClaudeService::parseMessageToResult($message, $this->config);

        $this->assertInstanceOf(ClaudeResult::class, $res);
        $this->assertSame(1, $res->successfulToolResponses);
        $this->assertNotEmpty($res->toolErrors);
        $this->assertSame('max_uses_exceeded', $res->toolErrors[0]['code']);
    }
}
