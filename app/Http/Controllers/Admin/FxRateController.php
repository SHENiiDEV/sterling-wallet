<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Models\FxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FxRateController extends Controller
{
    public function index(): Response
    {
        $rates = FxRate::query()->with('creator:id,name')->latest('rate_date')->latest('id')->paginate(30);

        $latest = FxRate::query()
            ->whereIn('id', FxRate::query()->selectRaw('max(id)')->groupBy('base', 'quote'))
            ->orderBy('base')->orderBy('quote')
            ->get();

        return Inertia::render('admin/fx-rates/index', [
            'rates' => $rates->through(fn (FxRate $rate) => $this->present($rate)),
            'latest' => $latest->map(fn (FxRate $rate) => $this->present($rate)),
            'baseCurrency' => config('sterling.base_currency'),
            'currencies' => Currency::values(),
            'quotes' => [...Currency::values(), 'USDC', 'USDT'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rate_date' => ['required', 'date', 'before_or_equal:today'],
            'base' => ['required', Rule::in(Currency::values())],
            'quote' => ['required', 'string', 'max:8', 'different:base', Rule::in([...Currency::values(), 'USDC', 'USDT'])],
            'rate' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ]);

        FxRate::query()->updateOrCreate(
            ['rate_date' => $data['rate_date'], 'base' => $data['base'], 'quote' => $data['quote']],
            ['rate' => $data['rate'], 'source' => 'manual', 'created_by' => $request->user()->id],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rate saved.']);

        return back();
    }

    public function destroy(FxRate $fxRate): RedirectResponse
    {
        $fxRate->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rate removed.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FxRate $rate): array
    {
        return [
            'id' => $rate->id,
            'rate_date' => $rate->rate_date->toDateString(),
            'base' => $rate->base,
            'quote' => $rate->quote,
            'rate' => $rate->rate,
            'source' => $rate->source,
            'created_by' => $rate->creator?->name,
        ];
    }
}
