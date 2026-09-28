<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Module;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff and their module access. Merchant logins never show up here.
 */
class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/team/index', [
            'members' => User::query()
                ->whereIn('role', [UserRole::SuperAdmin, UserRole::Admin])
                ->orderByRaw("case when role = 'super_admin' then 0 else 1 end")
                ->orderBy('name')
                ->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role->value,
                    'role_label' => $u->role->label(),
                    'permissions' => $u->permissions ?? [],
                    'is_active' => $u->is_active,
                    'two_factor' => $u->two_factor_confirmed_at !== null,
                    'last_login_at' => $u->last_login_at?->toIso8601String(),
                    'is_me' => $u->id === $request->user()->id,
                ]),
            'modules' => Module::options(),
            'roles' => [
                ['value' => UserRole::Admin->value, 'label' => UserRole::Admin->label()],
                ['value' => UserRole::SuperAdmin->value, 'label' => UserRole::SuperAdmin->label()],
            ],
            'canManageSuperAdmins' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $password = $data['password'] ?? Str::password(16, symbols: false);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $password,
            'role' => $data['role'],
            'permissions' => $data['role'] === UserRole::SuperAdmin->value ? null : array_values($data['permissions'] ?? []),
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        AuditLogger::log('team.created', $user, ['role' => $user->role->value, 'permissions' => $user->permissions]);

        // Shown once: the password is never stored in clear text.
        Inertia::flash('credentials', ['email' => $user->email, 'password' => $password]);

        return back();
    }

    public function update(Request $request, User $member): RedirectResponse
    {
        $this->assertStaff($member);
        $data = $this->validated($request, $member);

        if ($member->id === $request->user()->id && ($data['role'] !== $member->role->value || ! ($data['is_active'] ?? true))) {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role or deactivate yourself.']);
        }
        $this->guardLastSuperAdmin($member, $data['role'], (bool) ($data['is_active'] ?? true));

        $member->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'permissions' => $data['role'] === UserRole::SuperAdmin->value ? null : array_values($data['permissions'] ?? []),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        AuditLogger::log('team.updated', $member, ['role' => $member->role->value, 'permissions' => $member->permissions, 'is_active' => $member->is_active]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->name} saved."]);

        return back();
    }

    public function resetPassword(Request $request, User $member): RedirectResponse
    {
        $this->assertStaff($member);
        $this->assertCanTouch($request->user(), $member);

        $password = Str::password(16, symbols: false);
        $member->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        AuditLogger::log('team.password_reset', $member);

        Inertia::flash('credentials', ['email' => $member->email, 'password' => $password]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $member = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member?->id)],
            'role' => ['required', Rule::in([UserRole::Admin->value, UserRole::SuperAdmin->value])],
            'permissions' => ['array'],
            'permissions.*' => [Rule::enum(Module::class)],
            'is_active' => ['boolean'],
            'password' => [$member ? 'prohibited' : 'nullable', 'string', 'min:12', 'max:255'],
        ]);

        $actor = $request->user();
        // Only super admins create, edit or promote super admins.
        if (! $actor->isSuperAdmin() && ($data['role'] === UserRole::SuperAdmin->value || $member?->role === UserRole::SuperAdmin)) {
            throw ValidationException::withMessages(['role' => 'Only a super admin can manage super admins.']);
        }
        // Nobody hands out access they don't have themselves.
        $granted = array_diff($data['permissions'] ?? [], $actor->accessibleModules());
        if ($granted !== []) {
            throw ValidationException::withMessages(['permissions' => 'You cannot grant access you do not have: '.implode(', ', $granted).'.']);
        }

        return $data;
    }

    private function assertStaff(User $member): void
    {
        abort_unless($member->role->isStaff(), 404);
    }

    private function assertCanTouch(User $actor, User $member): void
    {
        if ($member->role === UserRole::SuperAdmin && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages(['member' => 'Only a super admin can manage super admins.']);
        }
    }

    private function guardLastSuperAdmin(User $member, string $role, bool $active): void
    {
        if ($member->role !== UserRole::SuperAdmin || ($role === UserRole::SuperAdmin->value && $active)) {
            return;
        }

        $others = User::query()->where('role', UserRole::SuperAdmin)->where('is_active', true)->whereKeyNot($member->id)->exists();
        if (! $others) {
            throw ValidationException::withMessages(['role' => 'This is the last active super admin.']);
        }
    }
}
