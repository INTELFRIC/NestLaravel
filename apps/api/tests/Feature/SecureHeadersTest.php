<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecureHeadersTest extends TestCase
{
    public function test_api_responses_keep_the_strict_content_security_policy(): void
    {
        $this->get('/up')
            ->assertHeader('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'")
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_api_docs_page_may_load_its_cdn_bundle_and_inline_scripts(): void
    {
        $csp = $this->get('/docs/api')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' https://unpkg.com", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline' https://unpkg.com", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }
}
