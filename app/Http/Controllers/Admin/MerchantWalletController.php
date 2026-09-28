<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MerchantWalletRequest;
use App\Models\Merchant;
use App\Models\MerchantCryptoWallet;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MerchantWalletController extends Controller
{
    public function store(MerchantWalletRequest $request, Merchant $merchant): RedirectResponse
    {
        $wallet = $merchant->wallets()->create($this->payload($request));

        AuditLogger::log('wallet.created', $wallet, ['merchant' => $merchant->public_id, 'with_seed' => $wallet->seed_phrase !== null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Wallet added.']);

        return back();
    }

    public function update(MerchantWalletRequest $request, Merchant $merchant, MerchantCryptoWallet $wallet): RedirectResponse
    {
        $wallet->update($this->payload($request));

        AuditLogger::log('wallet.updated', $wallet, ['merchant' => $merchant->public_id, 'fields' => array_keys($wallet->getChanges())]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Wallet saved.']);

        return back();
    }

    public function destroy(Merchant $merchant, MerchantCryptoWallet $wallet): RedirectResponse
    {
        AuditLogger::log('wallet.deleted', $wallet, ['merchant' => $merchant->public_id, 'address' => $wallet->address]);
        $wallet->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Wallet removed.']);

        return back();
    }

    /**
     * Reveal a seed phrase. Super admins only; every view is audited.
     */
    public function seed(Request $request, Merchant $merchant, MerchantCryptoWallet $wallet): JsonResponse
    {
        abort_unless($request->user()->role === UserRole::SuperAdmin, 403);

        AuditLogger::log('wallet.seed_viewed', $wallet, ['merchant' => $merchant->public_id]);

        return response()->json(['seed_phrase' => $wallet->seed_phrase]);
    }

    /**
     * Only super admins may set a seed; an empty field keeps the stored one.
     *
     * @return array<string, mixed>
     */
    private function payload(MerchantWalletRequest $request): array
    {
        $data = $request->validated();

        if (! $request->filled('seed_phrase') || $request->user()->role !== UserRole::SuperAdmin) {
            unset($data['seed_phrase']);
        }

        return $data;
    }
}
