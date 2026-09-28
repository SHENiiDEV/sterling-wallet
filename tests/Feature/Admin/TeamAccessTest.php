<?php

namespace Tests\Feature\Admin;

use App\Enums\Module;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_without_modules_sees_only_the_dashboard()
    {
        $admin = User::factory()->withModules([])->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.modules', [])
                ->where('profit', null)
                ->where('documents', false));

        foreach (['admin.merchants.index', 'admin.reports.index', 'admin.settlements.index', 'admin.profit.index', 'admin.offers.index', 'admin.team.index', 'admin.documents.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertForbidden();
        }
    }

    public function test_modules_open_exactly_their_screens()
    {
        $admin = User::factory()->withModules([Module::Reports, Module::Offers])->create();

        $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.offers.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.settlements.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.merchants.index'))->assertForbidden();
    }

    public function test_super_admin_has_everything_by_role()
    {
        $super = User::factory()->superAdmin()->withModules([])->create();

        $this->actingAs($super)->get(route('admin.team.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.modules', Module::values()));
    }

    public function test_creating_a_member_shows_the_password_once()
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->post(route('admin.team.store'), [
            'name' => 'Anna Ops',
            'email' => 'anna@sterling.test',
            'role' => 'admin',
            'permissions' => ['reports', 'settlements'],
        ])->assertRedirect()->assertInertiaFlash('credentials.email', 'anna@sterling.test');

        $anna = User::query()->where('email', 'anna@sterling.test')->sole();
        $this->assertSame(UserRole::Admin, $anna->role);
        $this->assertSame(['reports', 'settlements'], $anna->permissions);
        $this->assertSame($super->id, $anna->created_by);
        $this->assertNotNull($anna->email_verified_at);
    }

    public function test_nobody_grants_access_they_do_not_have()
    {
        $lead = User::factory()->withModules([Module::Team, Module::Reports])->create();

        $this->actingAs($lead)->post(route('admin.team.store'), [
            'name' => 'X', 'email' => 'x@sterling.test', 'role' => 'admin', 'permissions' => ['settlements'],
        ])->assertSessionHasErrors('permissions');

        $this->actingAs($lead)->post(route('admin.team.store'), [
            'name' => 'Y', 'email' => 'y@sterling.test', 'role' => 'super_admin', 'permissions' => [],
        ])->assertSessionHasErrors('role');

        $this->actingAs($lead)->post(route('admin.team.store'), [
            'name' => 'Z', 'email' => 'z@sterling.test', 'role' => 'admin', 'permissions' => ['reports'],
        ])->assertSessionHasNoErrors();
    }

    public function test_the_last_super_admin_cannot_be_demoted_or_deactivated()
    {
        $super = User::factory()->superAdmin()->create();
        $other = User::factory()->superAdmin()->create();

        // Self-demotion is blocked outright.
        $this->actingAs($super)->put(route('admin.team.update', $super), [
            'name' => $super->name, 'email' => $super->email, 'role' => 'admin', 'is_active' => true,
        ])->assertSessionHasErrors('role');

        // Demoting another super admin is fine while one stays.
        $this->actingAs($super)->put(route('admin.team.update', $other), [
            'name' => $other->name, 'email' => $other->email, 'role' => 'admin', 'permissions' => ['reports'], 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame(UserRole::Admin, $other->fresh()->role);
    }

    public function test_password_reset_generates_a_new_password()
    {
        $super = User::factory()->superAdmin()->create();
        $member = User::factory()->create();
        $old = $member->password;

        $this->actingAs($super)->post(route('admin.team.password', $member))->assertRedirect();

        $this->assertNotSame($old, $member->fresh()->password);
    }

    public function test_merchant_logins_are_not_listed()
    {
        $super = User::factory()->superAdmin()->create();
        User::factory()->merchant()->create(['email' => 'portal@merchant.test']);

        $this->actingAs($super)->get(route('admin.team.index'))
            ->assertInertia(fn (Assert $page) => $page->has('members', 1));

        $merchantUser = User::query()->where('email', 'portal@merchant.test')->sole();
        $this->actingAs($super)->put(route('admin.team.update', $merchantUser), [
            'name' => 'x', 'email' => 'portal@merchant.test', 'role' => 'admin',
        ])->assertNotFound();
        $this->assertTrue(Hash::check('password', $merchantUser->fresh()->password));
    }
}
