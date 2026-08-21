<?php

declare(strict_types=1);

namespace App\Tests;

final class PagesTest extends AppTestCase
{
    public function test_the_home_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('It runs.');
    }

    public function test_the_health_endpoint_answers_json(): void
    {
        $this->get('/api/health')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_an_unknown_path_is_a_404(): void
    {
        $this->get('/nowhere')->assertNotFound();
    }

    public function test_security_headers_are_present_on_every_page(): void
    {
        $this->get('/')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_security_headers_are_present_on_errors_too(): void
    {
        $this->get('/nowhere')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_bundled_script_is_served(): void
    {
        $this->get('/_phpvin/phpvin.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
    }
}
