<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_carries_the_tags_search_engines_and_social_networks_read()
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'">', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('property="og:image" content="'.url('/images/og-image.png').'"', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertFileExists(public_path('images/og-image.png'));
    }

    public function test_the_login_and_the_console_stay_out_of_search_results()
    {
        $login = $this->get(route('login'))->assertOk();
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $login->getContent());
        $login->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get('/admin')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_robots_and_sitemap_point_search_engines_at_the_public_page_only()
    {
        $robots = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Disallow: /admin', $robots);
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $robots);

        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->assertSee('<loc>'.url('/').'</loc>', false);
    }
}
