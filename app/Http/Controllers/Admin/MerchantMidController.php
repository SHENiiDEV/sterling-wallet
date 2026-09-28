<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MerchantMidRequest;
use App\Models\Merchant;
use App\Models\MerchantMid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MerchantMidController extends Controller
{
    public function store(MerchantMidRequest $request, Merchant $merchant): RedirectResponse
    {
        $merchant->mids()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'MID added.']);

        return back();
    }

    public function update(MerchantMidRequest $request, Merchant $merchant, MerchantMid $mid): RedirectResponse
    {
        $data = $request->validated();

        if ($data['currency'] !== $mid->currency->value && ($mid->operations()->exists() || $mid->dailyReports()->exists())) {
            throw ValidationException::withMessages([
                'currency' => 'Currency cannot change once the MID has operations. Create a new MID instead.',
            ]);
        }

        $mid->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'MID saved.']);

        return back();
    }

    public function destroy(Merchant $merchant, MerchantMid $mid): RedirectResponse
    {
        if ($mid->operations()->exists() || $mid->dailyReports()->exists() || $mid->reserveEntries()->exists()) {
            throw ValidationException::withMessages([
                'mid' => 'This MID has history. Set it to inactive instead of deleting.',
            ]);
        }

        $mid->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'MID removed.']);

        return back();
    }
}
