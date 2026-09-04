<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PromptRepository;
use RuntimeException;
use Tests\TestCase;

class PromptRepositoryTest extends TestCase
{
    private function repo(): PromptRepository
    {
        return new PromptRepository(resource_path('prompts'));
    }

    /** @return array<string, string> */
    private function vars(): array
    {
        return [
            'target_query' => 'mostbet login',
            'geo' => 'PL',
            'language' => 'pl-PL',
            'domain' => 'example.com',
            'page_type' => 'обзор',
            'site_type' => 'партнёрский казино-сайт',
            'page_goal' => 'регистрации',
        ];
    }

    /**
     * Незаполненный плейсхолдер уезжает в промпт молча и портит результат,
     * поэтому проверяем каждый шаблон, который может понадобиться режимам.
     */
    public function test_every_stage_referenced_by_a_mode_renders_completely(): void
    {
        $stages = collect(config('textgen.modes'))
            ->pluck('stages')
            ->flatten()
            ->unique();

        $this->assertNotEmpty($stages);

        foreach ($stages as $stage) {
            $rendered = $this->repo()->stage($stage, $this->vars());

            $this->assertStringNotContainsString('{{', $rendered, "стадия {$stage}");
            $this->assertNotEmpty(trim($rendered), "стадия {$stage} пуста");
        }
    }

    public function test_every_mode_has_its_own_prompt_file(): void
    {
        foreach (array_keys(config('textgen.modes')) as $mode) {
            $rendered = $this->repo()->mode($mode, $this->vars());

            $this->assertStringNotContainsString('{{', $rendered, "режим {$mode}");
            $this->assertNotEmpty(trim($rendered), "режим {$mode} пуст");
        }
    }

    public function test_the_rules_carry_the_market_into_the_prompt(): void
    {
        $rules = $this->repo()->rules($this->vars());

        $this->assertStringContainsString('PL', $rules);
        $this->assertStringContainsString('pl-PL', $rules);
        $this->assertStringNotContainsString('{{', $rules);
    }

    public function test_the_no_invented_data_rule_survives_editing(): void
    {
        // Правило про «нет данных» — причина существования всей системы.
        // Если его вычистят из шаблона, тест обязан упасть.
        $rules = $this->repo()->rules($this->vars());

        $this->assertStringContainsString('нет данных', $rules);
        $this->assertStringContainsString('SUPPLIED DATA', $rules);
    }

    public function test_a_missing_variable_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('не подставлена переменная');

        $this->repo()->rules(['geo' => 'PL']);
    }

    public function test_a_missing_template_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Промпт не найден');

        $this->repo()->stage('no-such-stage', $this->vars());
    }
}
