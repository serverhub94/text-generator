<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AccessCodeFlowTest extends TestCase
{
    use RefreshDatabase;

    public function testSuccessfulAccessCodeRedirectsToIntended(): void
    {
        config(['textgen.limits.access_code' => 'secret-code']);

        // Сначала заход на защищённый URL — middleware должен сохранить intended
        $this->get('/')->assertStatus(401);

        $this->assertEquals(url('/'), session('url.intended'));

        // Устанавливаем CSRF токен в сессии и отправляем POST с этим токеном
        $csrf = 'test-csrf-token';
        $this->withSession(['_token' => $csrf]);

        $post = $this->post('/access', [
            'access_code' => 'secret-code',
            '_token' => $csrf,
        ]);

        $post->assertRedirect('/');

        $this->assertEquals('secret-code', session('textgen_access'));
    }

    public function testFailedAccessCodeReturnsGateViewAndDoesNotSetSession(): void
    {
        config(['textgen.limits.access_code' => 'secret-code']);

        // Сначала посетим защищённый URL, чтобы intended сохранился
        $this->get('/')->assertStatus(401);
        $this->assertEquals(url('/'), session('url.intended'));

        // Устанавливаем CSRF токен в сессии и отправляем неверный код
        $csrf = 'test-csrf-token-2';
        $this->withSession(['_token' => $csrf]);

        $post = $this->post('/access', [
            'access_code' => 'wrong',
            '_token' => $csrf,
        ]);

        // Ожидаем 401 (форма с failed)
        $post->assertStatus(401);

        $this->assertNull(session('textgen_access'));
    }
}
