<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_admins_can_visit_the_dashboard()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/dashboard')->has('stats'));
    }

    public function test_merchants_cannot_enter_the_admin_panel()
    {
        $this->actingAs(User::factory()->merchant()->create());

        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_inactive_admins_cannot_enter_the_admin_panel()
    {
        $this->actingAs(User::factory()->inactive()->create());

        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_legacy_dashboard_url_redirects_to_admin()
    {
        $this->get('/dashboard')->assertRedirect('/admin');
    }
}
