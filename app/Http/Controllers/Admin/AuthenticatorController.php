<?php

namespace App\Http\Controllers\Admin;

use App\Authenticator\AuthenticatorCodes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuthenticatorCodeRequest;
use App\Models\AuthenticatorCode;
use App\Models\Client;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\Provider;
use App\Services\AuditLogger;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The team's authenticator (TOTP) secrets, kept encrypted, with the six-digit codes they produce shown here
 * so nobody needs a phone to log in to Oxen, the mailbox or a bank. The secret itself never leaves the server.
 */
class AuthenticatorController extends Controller
{
    public function index(AuthenticatorCodes $codes): Response
    {
        return Inertia::render('admin/authenticator/index', [
            'codes' => $codes->present(AuthenticatorCode::query()->orderBy('name')->get()),
            'targets' => [
                'client' => Client::query()->orderBy('name')->get(['id', 'name'])->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])->all(),
                'company' => Company::query()->orderBy('name')->get(['id', 'name'])->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])->all(),
                'merchant' => Merchant::query()->orderBy('name')->get(['id', 'name', 'website'])->map(fn (Merchant $m) => ['id' => $m->id, 'name' => $m->name])->all(),
                'provider' => Provider::query()->orderBy('name')->get(['id', 'name'])->map(fn (Provider $p) => ['id' => $p->id, 'name' => $p->name])->all(),
            ],
        ]);
    }

    /**
     * The codes valid now, by id: polled by the pages that show them. Each person's first look at a code
     * is written to the audit log, then once more after five minutes.
     */
    public function codes(Request $request): JsonResponse
    {
        $ids = array_filter(array_map('intval', (array) $request->query('ids', [])));

        $result = [];
        foreach (AuthenticatorCode::query()->whereIn('id', $ids)->where('is_active', true)->get() as $code) {
            $result[$code->id] = $code->current();

            if (Cache::add("authenticator:seen:{$request->user()->id}:{$code->id}", true, 300)) {
                AuditLogger::log('authenticator.code_viewed', $code, ['name' => $code->name]);
            }
        }

        return response()->json((object) $result)->header('Cache-Control', 'no-store');
    }

    public function store(AuthenticatorCodeRequest $request): RedirectResponse
    {
        $code = DB::transaction(function () use ($request) {
            $code = AuthenticatorCode::query()->create($this->attributes($request, null));
            $this->syncLinks($code, $request);

            return $code;
        });
        AuditLogger::log('authenticator.created', $code, ['name' => $code->name]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Code added.']);

        return back();
    }

    public function update(AuthenticatorCodeRequest $request, AuthenticatorCode $code): RedirectResponse
    {
        DB::transaction(function () use ($request, $code) {
            $code->update($this->attributes($request, $code));
            $this->syncLinks($code, $request);
        });
        AuditLogger::log('authenticator.updated', $code, ['name' => $code->name, 'secret_replaced' => $request->filled('secret')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return back();
    }

    public function destroy(AuthenticatorCode $code): RedirectResponse
    {
        AuditLogger::log('authenticator.deleted', $code, ['name' => $code->name]);
        $code->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Code removed.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(AuthenticatorCodeRequest $request, ?AuthenticatorCode $code): array
    {
        $attributes = [
            'name' => $request->string('name')->toString(),
            'service' => $request->filled('service') ? $request->string('service')->toString() : null,
            'notes' => $request->filled('notes') ? $request->string('notes')->toString() : null,
            'is_active' => $request->boolean('is_active', true),
            'digits' => (int) ($request->input('digits') ?: $code?->digits ?: 6),
            'period' => (int) ($request->input('period') ?: $code?->period ?: 30),
            'algorithm' => (string) ($request->input('algorithm') ?: $code?->algorithm ?: 'sha1'),
        ];

        if ($request->filled('secret')) {
            $input = $request->string('secret')->toString();

            try {
                $parsed = Totp::parse($input);
                // Prove the secret works before it is stored.
                Totp::code($parsed['secret'], $parsed['digits'], $parsed['period'], $parsed['algorithm']);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['secret' => $e->getMessage()]);
            }

            $attributes['secret'] = $parsed['secret'];
            // A QR-code link carries its own digits, period and algorithm.
            if (stripos(trim($input), 'otpauth://') === 0) {
                $attributes = [...$attributes, 'digits' => $parsed['digits'], 'period' => $parsed['period'], 'algorithm' => $parsed['algorithm']];
                $attributes['service'] ??= $parsed['issuer'];
            }
        }

        return $attributes;
    }

    private function syncLinks(AuthenticatorCode $code, AuthenticatorCodeRequest $request): void
    {
        $code->links()->delete();

        foreach (collect((array) $request->input('links', []))->unique(fn (array $l) => $l['type'].'|'.$l['id']) as $link) {
            $code->links()->create(['target_type' => $link['type'], 'target_id' => (int) $link['id']]);
        }
    }
}
