<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * Portal logins of a whole client: they see every company of the client
 * and download the consolidated daily report, or one company's part of it.
 */
class ClientPortalUserController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(10)],
        ]);

        $user = User::query()->create([
            ...$data,
            'role' => UserRole::Merchant,
            'client_id' => $client->id,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        AuditLogger::log('portal_user.created', $user, ['client' => $client->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Portal access for {$client->name} created for {$user->email}."]);

        return back();
    }

    public function update(Request $request, Client $client, User $user): RedirectResponse
    {
        $this->assertClientUser($client, $user);

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

    public function destroy(Client $client, User $user): RedirectResponse
    {
        $this->assertClientUser($client, $user);

        AuditLogger::log('portal_user.deleted', $user, ['email' => $user->email]);
        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Portal access removed.']);

        return back();
    }

    private function assertClientUser(Client $client, User $user): void
    {
        abort_unless($user->role === UserRole::Merchant && $user->client_id === $client->id, 404);
    }
}
