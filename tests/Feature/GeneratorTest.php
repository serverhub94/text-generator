<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunPipeline;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeneratorTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'target_query' => 'mostbet login',
            'geo' => 'PL',
            'language' => 'pl-PL',
            'mode' => 'v3',
            'keywords' => "mostbet login\t500",
        ], $overrides);
    }

    public function test_the_form_renders_with_every_mode(): void
    {
        $this->get(route('runs.create'))
            ->assertOk()
            ->assertSee('Ключевой запрос')
            ->assertSee('V1 · Базовый')
            ->assertSee('V7 · Моно-статья');
    }

    public function test_a_valid_submission_queues_a_run(): void
    {
        Queue::fake();

        $response = $this->post(route('runs.store'), $this->payload());

        $run = Run::sole();

        $this->assertSame(Run::STATUS_QUEUED, $run->status);
        $this->assertSame('v3', $run->mode);
        $this->assertSame('mostbet login', $run->input['target_query']);

        $response->assertRedirect(route('runs.show', $run));
        Queue::assertPushed(RunPipeline::class);
    }

    public function test_more_than_ten_target_queries_is_rejected(): void
    {
        Queue::fake();

        // Правило процесса, а не техническое ограничение: больше десяти
        // запросов размывают фокус анализа конкурентов.
        $queries = implode("\n", array_map(fn ($i) => "запрос {$i}", range(1, 11)));

        $this->post(route('runs.store'), $this->payload(['target_queries' => $queries]))
            ->assertSessionHasErrors('target_queries');

        $this->assertSame(0, Run::count());
        Queue::assertNothingPushed();
    }

    public function test_exactly_ten_target_queries_is_allowed(): void
    {
        Queue::fake();

        $queries = implode("\n", array_map(fn ($i) => "запрос {$i}", range(1, 10)));

        $this->post(route('runs.store'), $this->payload(['target_queries' => $queries]))
            ->assertSessionHasNoErrors();
    }

    public function test_geo_must_be_a_two_letter_code(): void
    {
        $this->post(route('runs.store'), $this->payload(['geo' => 'Poland']))
            ->assertSessionHasErrors('geo');
    }

    public function test_language_must_use_the_lang_LANG_shape(): void
    {
        $this->post(route('runs.store'), $this->payload(['language' => 'польский']))
            ->assertSessionHasErrors('language');
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $this->post(route('runs.store'), $this->payload(['mode' => 'v99']))
            ->assertSessionHasErrors('mode');
    }

    public function test_the_monthly_budget_blocks_new_runs(): void
    {
        Queue::fake();
        config(['textgen.limits.monthly_budget_usd' => 10]);

        Run::create([
            'status' => Run::STATUS_DONE,
            'mode' => 'v3',
            'input' => [],
            'cost_usd' => 12.50,
        ]);

        $this->post(route('runs.store'), $this->payload())
            ->assertSessionHasErrors('mode');

        Queue::assertNothingPushed();
    }

    public function test_the_status_endpoint_reports_progress(): void
    {
        $run = Run::create([
            'status' => Run::STATUS_RUNNING,
            'mode' => 'v3',
            'input' => $this->payload(),
            'stage' => 'research',
        ]);

        $this->getJson(route('runs.status', $run))
            ->assertOk()
            ->assertJson([
                'status' => 'running',
                'stage' => 'research',
                'stage_label' => 'Ресёрч: выдача, конкуренты, content gap',
                'finished' => false,
            ]);
    }

    public function test_a_rewrite_stage_reports_its_attempt_number(): void
    {
        $run = Run::create([
            'status' => Run::STATUS_RUNNING,
            'mode' => 'v4',
            'input' => $this->payload(),
            'stage' => 'rewrite:2',
        ]);

        $this->getJson(route('runs.status', $run))
            ->assertJsonPath('stage_label', 'Переписываю статью после аудита (попытка 2)');
    }

    public function test_a_finished_run_shows_every_stage_output(): void
    {
        $run = Run::create([
            'status' => Run::STATUS_DONE,
            'mode' => 'v3',
            'input' => $this->payload(),
            'article' => 'Готовый текст страницы',
            'stages' => ['research' => 'Отчёт по выдаче', 'brief' => 'ТЗ', 'article' => 'Готовый текст страницы'],
            'usage' => ['input' => 100, 'output' => 50, 'cache_read' => 0, 'cache_write' => 0, 'searches' => 2],
            'cost_usd' => 1.25,
        ]);

        $this->get(route('runs.show', $run))
            ->assertOk()
            ->assertSee('Готовый текст страницы')
            ->assertSee('Отчёт по выдаче')
            ->assertSee('Скачать .md');
    }

    public function test_each_stage_downloads_as_markdown(): void
    {
        $run = Run::create([
            'status' => Run::STATUS_DONE,
            'mode' => 'v3',
            'input' => $this->payload(),
            'article' => '# Текст',
            'stages' => ['research' => '# Отчёт'],
        ]);

        $this->get(route('runs.download', [$run, 'research']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertSee('# Отчёт');
    }

    public function test_downloading_a_stage_that_never_ran_is_a_404(): void
    {
        $run = Run::create([
            'status' => Run::STATUS_DONE,
            'mode' => 'v1',
            'input' => $this->payload(),
            'article' => '# Текст',
            'stages' => ['article' => '# Текст'],
        ]);

        $this->get(route('runs.download', [$run, 'research']))->assertNotFound();
    }

    public function test_a_failed_run_shows_the_reason(): void
    {
        $run = Run::create([
            'status' => Run::STATUS_FAILED,
            'mode' => 'v3',
            'input' => $this->payload(),
            'error' => 'Стадия «research» вернула пустой ответ.',
        ]);

        $this->get(route('runs.show', $run))
            ->assertOk()
            ->assertSee('вернула пустой ответ', false);
    }

    public function test_runs_are_addressed_by_uuid_not_by_id(): void
    {
        Queue::fake();
        $this->post(route('runs.store'), $this->payload());

        $run = Run::sole();

        $this->assertNotEmpty($run->uuid);
        $this->assertStringContainsString($run->uuid, route('runs.show', $run));
    }

    public function test_a_cyrillic_query_still_produces_a_usable_filename(): void
    {
        $run = Run::create([
            'status' => Run::STATUS_DONE,
            'mode' => 'v1',
            'input' => $this->payload(['target_query' => 'ставки на спорт']),
            'article' => '# Текст',
            'stages' => ['article' => '# Текст'],
        ]);

        $response = $this->get(route('runs.download', [$run, 'article']));

        $disposition = $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('-article.html', $disposition);
        // Пустое имя вида "-article.html" означало бы, что slug() всё съел.
        $this->assertStringNotContainsString('"-article.html"', $disposition);
    }

    /**
     * Статья — деливеринг для CMS, а не рабочий документ: она уходит готовым
     * HTML-фрагментом, тогда как ресёрч и ТЗ остаются markdown.
     */
    public function test_the_article_downloads_as_html(): void
    {
        $run = Run::create([
            'status' => Run::STATUS_DONE,
            'mode' => 'v1',
            'input' => $this->payload(),
            'article' => '<h1>Kasyna</h1>',
            'stages' => ['article' => '<h1>Kasyna</h1>'],
        ]);

        $response = $this->get(route('runs.download', [$run, 'article']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $this->assertSame('<h1>Kasyna</h1>', $response->getContent());
    }

    public function test_on_page_meta_is_shown_apart_from_the_markup(): void
    {
        $run = Run::create([
            'status' => Run::STATUS_DONE,
            'mode' => 'v1',
            'input' => $this->payload(),
            'article' => '<h1>Kasyna</h1>',
            'article_meta' => [
                'title' => 'Najlepsze kasyna online',
                'description' => 'Przeglad kasyn 2026.',
                'url' => '/kasyna-online',
                'notes' => 'Что заполнить руками: лицензии',
            ],
            'stages' => ['article' => '<h1>Kasyna</h1>'],
        ]);

        $this->get(route('runs.show', $run))
            ->assertOk()
            ->assertSee('Najlepsze kasyna online')
            ->assertSee('Przeglad kasyn 2026.')
            ->assertSee('/kasyna-online')
            ->assertSee('Служебное — не публиковать')
            ->assertSee('Скачать .html');
    }
}
