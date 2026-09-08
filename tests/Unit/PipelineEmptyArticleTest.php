<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\URL;

class PipelineEmptyArticleTest extends TestCase
{
    public function testEmptyArticleProducesWarningAndNullOutput(): void
    {
        // Заглушаем route() чтобы шаблон не падал при генерации ссылок
        URL::shouldReceive('route')->andReturn('#');

        // Подготовим "run" объект с минимальным интерфейсом, который использует шаблон
        $run = new class {
            public $status = 'done';
            public $article = ''; // пустая разметка
            public $warnings = [
                [
                    'stage' => 'article',
                    'reason' => 'empty_article',
                    'message' => 'Article markup is empty after parsing; no article will be shown or downloadable.',
                    'time' => 'now',
                ],
            ];
            public $input = ['target_query' => 'q', 'geo' => 'pl', 'language' => 'pl-PL'];
            public $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0, 'searches' => 0];
            public $cost_usd = 0.0;

            public function isFinished()
            {
                return true;
            }

            public function stageOutput($key)
            {
                return null;
            }

            public function articleMeta($field)
            {
                return '';
            }
        };

        // Минимальный режим, чтобы шаблон мог взять $mode['label'] и список стадий
        $mode = ['label' => 'VTEST', 'stages' => ['article']];

        // Рендерим view и проверяем поведение
        $view = $this->view('generator.show', ['run' => $run, 'mode' => $mode]);

        // Вкладка "Текст" не должна присутствовать
        $view->assertDontSee('Текст');

        // Проверяем локализованное сообщение, которое мы добавили в шаблон
        $view->assertSee('Пустая статья', false);

        // Кнопка "Скачать .html" для article не должна отображаться
        $view->assertDontSee('Скачать .html');
    }
}
