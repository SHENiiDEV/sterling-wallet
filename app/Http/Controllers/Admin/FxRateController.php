<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\DailyReportTask;
use App\Models\FxRate;
use App\Services\AuditLogger;
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

        $rate = FxRate::query()->firstOrNew(['rate_date' => $data['rate_date'], 'base' => $data['base'], 'quote' => $data['quote']]);
        $before = $rate->exists ? ['rate' => $rate->rate, 'source' => $rate->source] : null;
        $rate->fill(['rate' => $data['rate'], 'source' => 'manual', 'created_by' => $request->user()->id])->save();

        AuditLogger::log($before === null ? 'fx_rate.created' : 'fx_rate.updated', $rate, [
            'pair' => "{$rate->base}/{$rate->quote}", 'rate_date' => $rate->rate_date->toDateString(),
            'rate' => $rate->rate, 'source' => 'manual', 'before' => $before,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rate saved.']);

        return back();
    }

    public function destroy(FxRate $fxRate): RedirectResponse
    {
        AuditLogger::log('fx_rate.deleted', $fxRate, [
            'pair' => "{$fxRate->base}/{$fxRate->quote}", 'rate_date' => $fxRate->rate_date->toDateString(), 'rate' => $fxRate->rate, 'source' => $fxRate->source,
        ]);
        $fxRate->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rate removed.']);

        return back();
    }

    /**
     * One rate: what it is, how it moved, its journal (who and what set, changed or removed it) and the reports that
     * were converted at it.
     */
    public function show(FxRate $fxRate): Response
    {
        $fxRate->load('creator:id,name');
        $pair = ['base' => $fxRate->base, 'quote' => $fxRate->quote];
        $previous = FxRate::query()->where($pair)->where('rate_date', '<', $fxRate->rate_date)->orderByDesc('rate_date')->first();
        $next = FxRate::query()->where($pair)->where('rate_date', '>', $fxRate->rate_date)->orderBy('rate_date')->first();

        // Reports in the reporting currency converted at this rate: the pair's days from this one until the next rate.
        $reports = DailyReportTask::query()
            ->with('merchantMid:id,mid')
            ->where(fn ($q) => $q->where(['currency' => $fxRate->base, 'base_currency' => $fxRate->quote])->orWhere(['currency' => $fxRate->quote, 'base_currency' => $fxRate->base]))
            ->where('report_date', '>=', $fxRate->rate_date->toDateString())
            ->when($next, fn ($q) => $q->where('report_date', '<', $next->rate_date->toDateString()));

        return Inertia::render('admin/fx-rates/show', [
            'rate' => [...$this->present($fxRate, true), 'created_at' => $fxRate->created_at?->toIso8601String(), 'updated_at' => $fxRate->updated_at?->toIso8601String(), 'inverse' => (float) $fxRate->rate > 0 ? round(1 / (float) $fxRate->rate, 8) : null],
            'previous' => $previous ? ['id' => $previous->id, 'rate_date' => $previous->rate_date->toDateString(), 'rate' => $previous->rate] : null,
            'next' => $next ? ['id' => $next->id, 'rate_date' => $next->rate_date->toDateString(), 'rate' => $next->rate] : null,
            'journal' => AdminAuditLog::query()->with('user:id,name')
                ->where('subject_type', $fxRate->getMorphClass())->where('subject_id', $fxRate->id)
                ->latest('id')->limit(100)->get()
                ->map(fn (AdminAuditLog $log) => [
                    'id' => $log->id,
                    'at' => $log->created_at?->toIso8601String(),
                    'action' => $log->action,
                    'user' => $log->user?->name,
                    'meta' => $log->meta,
                ]),
            'reportsCount' => (clone $reports)->count(),
            'reports' => (clone $reports)->orderByDesc('report_date')->limit(20)->get()->map(fn (DailyReportTask $r) => [
                'id' => $r->id,
                'report_date' => $r->report_date->toDateString(),
                'mid' => $r->merchantMid->mid,
                'currency' => $r->currency,
                'fx_rate' => $r->fx_rate,
            ]),
        ]);
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
