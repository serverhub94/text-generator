<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_form_is_open_when_no_code_is_configured(): void
    {
        config(['textgen.limits.access_code' => '']);

        $this->get(route('runs.create'))->assertOk()->assertSee('Ключевой запрос');
    }

    public function test_a_configured_code_closes_the_form(): void
    {
        config(['textgen.limits.access_code' => 'секрет']);

        $this->get(route('runs.create'))
            ->assertStatus(401)
            ->assertSee('Код доступа');
    }

    public function test_the_right_code_opens_it_for_the_session(): void
    {
        config(['textgen.limits.access_code' => 'секрет']);

        $this->post(route('access.post'), ['access_code' => 'секрет'])
            ->assertRedirect();

        $this->get(route('runs.create'))->assertOk()->assertSee('Ключевой запрос');
    }

    public function test_a_wrong_code_is_refused(): void
    {
        config(['textgen.limits.access_code' => 'секрет']);

        $this->post(route('access.post'), ['access_code' => 'мимо'])
            ->assertStatus(401)
            ->assertSee('Код не подошёл');
    }
}
