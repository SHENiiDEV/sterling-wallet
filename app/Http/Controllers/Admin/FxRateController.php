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
    public function index(Request $request): Response
    {
        $pair = preg_match('/^([A-Z]{3,4})-([A-Z]{3,4})$/', (string) $request->query('pair'), $m) ? [$m[1], $m[2]] : null;

        $rates = FxRate::query()->with('creator:id,name')
            ->when($pair, fn ($q, array $p) => $q->where(['base' => $p[0], 'quote' => $p[1]]))
            ->latest('rate_date')->orderBy('base')->orderBy('quote')
            ->paginate(30)->withQueryString();

        // The newest rate of each pair by its date (a back-filled older day does not take its place).
        $latest = FxRate::query()->select('base', 'quote')->distinct()->orderBy('base')->orderBy('quote')->get()
            ->map(fn (FxRate $p) => FxRate::query()->where(['base' => $p->base, 'quote' => $p->quote])->orderByDesc('rate_date')->orderByDesc('id')->first())
            ->filter();

        return Inertia::render('admin/fx-rates/index', [
            'rates' => $rates->through(fn (FxRate $rate) => $this->present($rate, true)),
            'pairs' => FxRate::query()->select('base', 'quote')->distinct()->orderBy('base')->orderBy('quote')->get()->map(fn (FxRate $r) => "{$r->base}-{$r->quote}")->values(),
            'pair' => $pair ? implode('-', $pair) : null,
            'latest' => $latest->values()->map(fn (FxRate $rate) => $this->present($rate)),
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
    private function present(FxRate $rate, bool $withChange = false): array
    {
        // The change against the pair's previous day: how the rate moved.
        $previous = $withChange
            ? FxRate::query()->where(['base' => $rate->base, 'quote' => $rate->quote])->where('rate_date', '<', $rate->rate_date)->orderByDesc('rate_date')->value('rate')
            : null;

        return [
            'change_percent' => $previous !== null && (float) $previous > 0 ? round(((float) $rate->rate - (float) $previous) / (float) $previous * 100, 3) : null,
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
