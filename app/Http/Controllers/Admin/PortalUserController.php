<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * Logins for the merchant portal. A login belongs to a company and sees
 * that company and every company below it — e.g. a client group (APS)
 * with its companies and their merchants.
 */
class PortalUserController extends Controller
{
    public function store(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(10)],
        ]);

        $user = User::query()->create([
            ...$data,
            'role' => UserRole::Merchant,
            'company_id' => $company->id,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        AuditLogger::log('portal_user.created', $user, ['company' => $company->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Portal access for {$company->name} created for {$user->email}."]);

        return back();
    }

    public function update(Request $request, Company $company, User $user): RedirectResponse
    {
        $this->assertPortalUser($company, $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', Password::min(10)],
            'is_active' => ['required', 'boolean'],
        ]);

        $user->fill(array_filter($data, fn ($v, $k) => $k !== 'password' || filled($v), ARRAY_FILTER_USE_BOTH))->save();
        AuditLogger::log('portal_user.updated', $user, ['changed' => array_keys($user->getChanges())]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Portal user saved.']);

        return back();
    }

    public function destroy(Company $company, User $user): RedirectResponse
    {
        $this->assertPortalUser($company, $user);

        AuditLogger::log('portal_user.deleted', $user, ['email' => $user->email]);
        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Portal access removed.']);

        return back();
    }

    /**
     * Portal users who can see this company: its own and its parents'.
     *
     * @return list<array<string, mixed>>
     */
    public static function usersFor(Company $company): array
    {
        $chain = collect($company->ancestorsWithSelf());

        return User::query()
            ->where('role', UserRole::Merchant)
            ->whereIn('company_id', $chain->pluck('id'))
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->is_active,
                'company_id' => $u->company_id,
                'company' => $chain->firstWhere('id', $u->company_id)?->name,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'created_at' => $u->created_at?->toDateString(),
            ])
            ->all();
    }

    private function assertPortalUser(Company $company, User $user): void
    {
        abort_unless($user->role === UserRole::Merchant && $user->company_id === $company->id, 404);
    }
}
