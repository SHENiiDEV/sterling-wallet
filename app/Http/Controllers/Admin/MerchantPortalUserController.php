<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Logins for the merchant portal. Access is per company: a portal user
 * sees every merchant of the company the merchant belongs to.
 */
class MerchantPortalUserController extends Controller
{
    public function store(Request $request, Merchant $merchant): RedirectResponse
    {
        $companyId = $this->companyOf($merchant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(10)],
        ]);

        $user = User::query()->create([
            ...$data,
            'role' => UserRole::Merchant,
            'company_id' => $companyId,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        AuditLogger::log('portal_user.created', $user, ['merchant' => $merchant->public_id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Portal access created for {$user->email}."]);

        return back();
    }

    public function update(Request $request, Merchant $merchant, User $user): RedirectResponse
    {
        $this->assertPortalUser($merchant, $user);

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

    public function destroy(Merchant $merchant, User $user): RedirectResponse
    {
        $this->assertPortalUser($merchant, $user);

        AuditLogger::log('portal_user.deleted', $user, ['email' => $user->email]);
        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Portal access removed.']);

        return back();
    }

    private function companyOf(Merchant $merchant): int
    {
        if ($merchant->company_id === null) {
            throw ValidationException::withMessages(['portal' => 'Link this merchant to a company first (Edit → Company): portal access is given per company.']);
        }

        return $merchant->company_id;
    }

    private function assertPortalUser(Merchant $merchant, User $user): void
    {
        abort_unless($user->role === UserRole::Merchant && $user->company_id === $this->companyOf($merchant), 404);
    }
}
