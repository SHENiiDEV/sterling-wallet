<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MerchantMidRequest;
use App\Merchants\MidPurger;
use App\Models\Merchant;
use App\Models\MerchantMid;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    /**
     * Deletes a MID with its operations, reports and reserve. The MID number
     * must be typed to confirm; the dialog shows what goes with it first.
     */
    public function purge(Request $request, Merchant $merchant, MerchantMid $mid, MidPurger $purger): RedirectResponse
    {
        abort_unless($mid->merchant_id === $merchant->id, 404);

        $request->validate([
            'confirm' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($mid) {
                if (trim((string) $value) !== trim($mid->mid)) {
                    $fail('Type the MID number exactly to confirm.');
                }
            }],
        ]);

        $deleted = $purger->purge($mid, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            'MID %s deleted with %d operations and %d reports.',
            $mid->mid, $deleted['operations'], $deleted['daily_reports'],
        )]);

        return back();
    }
}
