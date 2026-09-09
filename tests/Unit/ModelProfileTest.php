<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ModelProfile;

class ModelProfileTest extends TestCase
{
    public function testModelProfileBasics(): void
    {
        $config = [
            'models' => [
                'test-model' => [
                    'label' => 'Test Model',
                    'id' => 'test-model',
                    'enabled' => true,
                    'pricing' => [
                        'input' => 0.001,
                        'output' => 0.002,
                        'cache_read' => 0.0001,
                        'cache_write' => 0.0001,
                    ],
                    'effort' => ['low', 'medium'],
                    'thinking' => ['short'],
                    'web_tools' => 'limited',
                    'max_output_tokens' => 5000,
                    'context_tokens' => 20000,
                ],
            ],
        ];

        $profile = ModelProfile::fromConfig('test-model', $config);

        $this->assertEquals('test-model', $profile->id());
        $this->assertEquals('Test Model', $profile->label());
        $this->assertTrue($profile->enabled());

        // effortFor: request 'high' -> should downgrade to 'medium'
        $this->assertEquals('medium', $profile->effortFor('high'));

        // maxTokensFor: requested 6000 -> limited to 5000
        $this->assertEquals(5000, $profile->maxTokensFor(6000));

        // cost: compute simple cost
        $cost = $profile->cost(1000, 2000, 10, 5);
        $expected = round(1000*0.001 + 2000*0.002 + 10*0.0001 + 5*0.0001, 6);
        $this->assertEquals($expected, $cost);
    }
}
