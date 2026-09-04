<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Pipeline;
use App\Services\PromptRepository;
use Tests\Support\FakeTextModel;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    /** @return array<string, mixed> */
    private function input(string $mode = 'v3'): array
    {
        return [
            'mode' => $mode,
            'target_query' => 'mostbet login',
            'geo' => 'pl',
            'language' => 'pl-PL',
            'domain' => 'example.com',
            'page_type' => 'обзор',
            'site_type' => 'партнёрский казино-сайт',
            'page_goal' => 'регистрации',
            'keywords' => "mostbet login\t500\nmostbet logowanie",
            'target_queries' => "mostbet login",
            'competitors' => '',
            'notes' => '',
        ];
    }

    private function pipeline(FakeTextModel $model): Pipeline
    {
        return new Pipeline(
            $model,
            new PromptRepository(resource_path('prompts')),
            config('textgen'),
        );
    }

    public function test_it_runs_every_stage_of_the_mode_in_order(): void
    {
        $model = new FakeTextModel(['ресёрч', 'тз', 'статья']);
        $seen = [];

        $result = $this->pipeline($model)
            ->onStage(function (string $stage) use (&$seen) { $seen[] = $stage; })
            ->run($this->input('v3'));

        $this->assertSame(['research', 'brief', 'article'], $seen);
        $this->assertSame(['research', 'brief', 'article'], array_keys($result['stages']));
        $this->assertSame('статья', $result['article']);
    }

    public function test_roles_alternate_across_the_growing_conversation(): void
    {
        $model = new FakeTextModel(['a', 'b', 'c']);
        $this->pipeline($model)->run($this->input('v3'));

        // К третьему вызову переписка — user/assistant/user/assistant/user.
        $this->assertSame(
            ['user', 'assistant', 'user', 'assistant', 'user'],
            $model->roleSequenceOfLastCall(),
        );
    }

    public function test_supplied_data_rides_only_in_the_first_message(): void
    {
        $model = new FakeTextModel(['a', 'b', 'c']);
        $this->pipeline($model)->run($this->input('v3'));

        $first = (string) $model->calls[0]['messages'][0]['content'];

        $this->assertStringContainsString('# SUPPLIED DATA', $first);
        $this->assertStringContainsString('| mostbet login | 500 |', $first);
        // Ключ без частотности обязан дойти до модели как «нет данных».
        $this->assertStringContainsString('| mostbet logowanie | нет данных |', $first);

        // Второе задание — только инструкция стадии, данные не дублируются.
        $this->assertStringNotContainsString('# SUPPLIED DATA', $model->calls[1]['messages'][2]['content']);
    }

    public function test_web_tools_are_enabled_only_for_the_research_stage(): void
    {
        $model = new FakeTextModel(['a', 'b', 'c']);
        $this->pipeline($model)->run($this->input('v3'));

        $this->assertTrue($model->calls[0]['webTools'], 'ресёрч ходит в веб');
        $this->assertFalse($model->calls[1]['webTools'], 'ТЗ в веб не ходит');
        $this->assertFalse($model->calls[2]['webTools'], 'статья в веб не ходит');
    }

    public function test_a_mode_without_research_never_touches_the_web(): void
    {
        $model = new FakeTextModel(['статья']);
        $this->pipeline($model)->run($this->input('v1'));

        $this->assertCount(1, $model->calls);
        $this->assertFalse($model->calls[0]['webTools']);
    }

    public function test_the_article_stage_carries_the_mode_prompt(): void
    {
        $model = new FakeTextModel(['ресёрч', 'тз', 'статья']);
        $this->pipeline($model)->run($this->input('v3'));

        $this->assertStringContainsString('Mode V3', $model->lastUserContent());
    }

    public function test_effort_and_token_ceiling_come_from_the_mode(): void
    {
        $model = new FakeTextModel(['ресёрч', 'тз', 'статья']);
        $this->pipeline($model)->run($this->input('v3'));

        $this->assertSame('high', $model->calls[2]['effort']);
        $this->assertSame(32000, $model->calls[2]['maxTokens'], 'у статьи потолок режима');
        $this->assertSame(24000, $model->calls[0]['maxTokens'], 'у ресёрча служебный потолок');
    }

    public function test_a_rejected_audit_triggers_a_rewrite_and_a_re_audit(): void
    {
        $model = new FakeTextModel([
            'ресёрч', 'тз', 'первая статья',
            'Вердикт: НЕ ПРОШЁЛ — выдуманные цифры.',
            'переписанная статья',
            'Вердикт: ПРОШЁЛ',
        ]);

        $seen = [];

        $result = $this->pipeline($model)
            ->onStage(function (string $stage) use (&$seen) { $seen[] = $stage; })
            ->run($this->input('v4'));

        $this->assertSame(
            ['research', 'brief', 'article', 'audit', 'rewrite:1', 'audit:1'],
            $seen,
        );
        $this->assertSame('переписанная статья', $result['article']);
        $this->assertStringContainsString('ПРОШЁЛ', $result['stages']['audit']);
    }

    public function test_a_passing_audit_leaves_the_article_alone(): void
    {
        $model = new FakeTextModel([
            'ресёрч', 'тз', 'статья', 'Вердикт: ПРОШЁЛ',
        ]);

        $result = $this->pipeline($model)->run($this->input('v4'));

        $this->assertCount(4, $model->calls, 'переписывания быть не должно');
        $this->assertSame('статья', $result['article']);
    }

    public function test_an_audit_with_remarks_is_not_treated_as_a_rejection(): void
    {
        $model = new FakeTextModel([
            'ресёрч', 'тз', 'статья', 'Вердикт: ПРОШЁЛ С ЗАМЕЧАНИЯМИ',
        ]);

        $this->pipeline($model)->run($this->input('v4'));

        $this->assertCount(4, $model->calls);
    }

    public function test_rewrites_stop_at_the_mode_limit(): void
    {
        // Аудит бракует всё подряд — конвейер обязан остановиться, а не
        // жечь бюджет до бесконечности.
        $model = new FakeTextModel([
            'ресёрч', 'тз', 'статья',
            'НЕ ПРОШЁЛ', 'статья 2', 'НЕ ПРОШЁЛ', 'статья 3', 'НЕ ПРОШЁЛ',
        ]);

        $result = $this->pipeline($model)->run($this->input('v4'));

        $this->assertCount(8, $model->calls);
        $this->assertSame('статья 3', $result['article']);
    }

    public function test_usage_and_cost_accumulate_across_stages(): void
    {
        $model = new FakeTextModel(['ресёрч', 'тз', 'статья']);
        $result = $this->pipeline($model)->run($this->input('v3'));

        $this->assertSame(3000, $result['usage']['input']);
        $this->assertSame(1500, $result['usage']['output']);
        $this->assertSame(600, $result['usage']['cache_read']);
        $this->assertSame(3, $result['usage']['searches'], 'поиск был только на ресёрче');
        $this->assertEqualsWithDelta(0.0525, $result['cost'], 0.0001);
    }

    public function test_geo_reaches_the_model_for_serp_localisation(): void
    {
        $model = new FakeTextModel(['ресёрч', 'тз', 'статья']);
        $this->pipeline($model)->run($this->input('v3'));

        $this->assertSame('pl', $model->calls[0]['geo']);
    }

    /**
     * Деливеринг — чистый HTML-фрагмент. Мета и служебные пометки редактора
     * идут отдельными полями: в разметке, которую вставляют в CMS, им не место.
     */
    public function test_the_article_comes_back_as_a_clean_html_fragment(): void
    {
        $model = new FakeTextModel(['ресёрч', 'тз', <<<'TXT'
        ===META===
        title: Najlepsze kasyna
        description: Opis strony
        url: /kasyna
        ===HTML===
        <div class="wp-block"><h1 style="color:red">Kasyna</h1><p>Tekst</p></div>
        ===NOTES===
        - Что заполнить руками: лицензии
        TXT]);

        $result = $this->pipeline($model)->run($this->input('v3'));

        $this->assertSame("<h1>Kasyna</h1>
<p>Tekst</p>", $result['article']);
        $this->assertSame($result['article'], $result['stages']['article']);

        $this->assertSame('Najlepsze kasyna', $result['article_meta']['title']);
        $this->assertSame('Opis strony', $result['article_meta']['description']);
        $this->assertSame('/kasyna', $result['article_meta']['url']);
        $this->assertStringContainsString('лицензии', $result['article_meta']['notes']);
    }

    /**
     * Переписывание после аудита не должно возвращать редактора к грязной
     * разметке: чистится итог, а не только первый вариант.
     */
    public function test_a_rewritten_article_is_cleaned_too(): void
    {
        $model = new FakeTextModel([
            'ресёрч', 'тз', '<p>первый</p>',
            'НЕ ПРОШЁЛ', '<div id="x"><p>второй</p></div>', 'ПРОШЁЛ',
        ]);

        $result = $this->pipeline($model)->run($this->input('v4'));

        $this->assertSame('<p>второй</p>', $result['article']);
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $this->expectExceptionMessage('Неизвестный режим: v99');

        $this->pipeline(new FakeTextModel())->run($this->input('v99'));
    }
}
