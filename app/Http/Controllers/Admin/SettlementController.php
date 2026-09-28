<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SettlementStatus;
use App\Http\Controllers\Controller;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\ReserveLedgerEntry;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Services\AuditLogger;
use App\Settlements\SettlementService;
use App\Settlements\SettlementStatementPdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettlementController extends Controller
{
    public function __construct(private SettlementService $settlements) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(SettlementStatus::class)],
            'merchant' => ['nullable', 'string'],
        ]);

        $list = Settlement::query()
            ->with(['merchant:id,public_id,name'])
            ->withCount('lines')
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($filters['merchant'] ?? null, fn (Builder $q, string $m) => $q->whereHas('merchant', fn (Builder $q) => $q->where('public_id', $m)))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Settlement $s) => $this->present($s));

        // Merchants with money waiting to be settled.
        $pending = DailyReportTask::query()->toBase()
            ->join('merchants', 'merchants.id', '=', 'daily_report_tasks.merchant_id')
            ->where('daily_report_tasks.status', 'completed')
            ->whereNotIn('daily_report_tasks.id', SettlementLine::query()->select('daily_report_task_id')
                ->where('type', 'report')->whereNotNull('daily_report_task_id')
                ->whereHas('settlement', fn (Builder $q) => $q->whereIn('status', SettlementStatus::active())))
            ->selectRaw('merchants.public_id, merchants.name, daily_report_tasks.currency, count(*) as reports, sum(daily_report_tasks.net_payout) as payout')
            ->groupBy('merchants.public_id', 'merchants.name', 'daily_report_tasks.currency')
            ->orderBy('merchants.name')
            ->get()
            ->map(fn ($r) => ['public_id' => $r->public_id, 'name' => $r->name, 'currency' => $r->currency, 'reports' => (int) $r->reports, 'payout' => round((float) $r->payout, 2)]);

        return Inertia::render('admin/settlements/index', [
            'settlements' => $list,
            'pending' => $pending,
            'filters' => (object) $filters,
            'statuses' => SettlementStatus::options(),
            'merchants' => Merchant::query()->orderBy('name')->get(['public_id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['merchant' => ['required', 'string', 'exists:merchants,public_id']]);
        $merchant = Merchant::query()->where('public_id', $data['merchant'])->firstOrFail();

        $settlement = $this->settlements->createDraft($merchant, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Draft {$settlement->number} created with {$settlement->lines()->count()} line(s)."]);

        return to_route('admin.settlements.show', $settlement);
    }

    public function show(Settlement $settlement): Response
    {
        $settlement->load(['merchant.wallets', 'lines.dailyReport.merchantMid:id,mid', 'creator:id,name', 'approver:id,name', 'settler:id,name']);
        $editable = $settlement->isEditable();

        return Inertia::render('admin/settlements/show', [
            'settlement' => [
                ...$this->present($settlement),
                'rates' => (object) ($settlement->rates ?? []),
                'notes' => $settlement->notes,
                'wallet_id' => $settlement->wallet_id,
                'creator' => $settlement->creator?->name,
                'approver' => $settlement->approver?->name,
                'approved_at' => $settlement->approved_at?->toIso8601String(),
                'settler' => $settlement->settler?->name,
                'settled_at' => $settlement->settled_at?->toIso8601String(),
                'tx_hash' => $settlement->tx_hash,
                'has_proof' => $settlement->proof_path !== null,
                'cancelled_at' => $settlement->cancelled_at?->toIso8601String(),
                'cancel_reason' => $settlement->cancel_reason,
                'missing_rates' => $this->settlements->missingRates($settlement),
            ],
            'lines' => $settlement->lines->sortBy('id')->values()->map(fn (SettlementLine $l) => [
                'id' => $l->id,
                'type' => $l->type->value,
                'type_label' => $l->type->label(),
                'description' => $l->description,
                'report_id' => $l->daily_report_task_id,
                'currency' => $l->currency,
                'amount' => $l->amount,
                'rate' => $l->rate,
                'amount_payout' => $l->amount_payout,
            ]),
            'wallets' => $settlement->merchant->wallets->where('is_active', true)->values()->map(fn ($w) => [
                'id' => $w->id,
                'label' => $w->label ?: $w->type->label(),
                'currency' => $w->currency,
                'network' => $w->network,
                'address' => $w->address,
            ]),
            'availableReports' => $editable ? $this->settlements->availableReports($settlement->merchant)->map(fn (DailyReportTask $t) => [
                'id' => $t->id,
                'report_date' => $t->report_date->toDateString(),
                'mid' => $t->merchantMid->mid,
                'currency' => $t->currency,
                'net_payout' => $t->getRawOriginal('net_payout'),
            ]) : [],
            'availableReleases' => $editable ? $this->settlements->availableReleases($settlement->merchant)->map(fn (ReserveLedgerEntry $e) => [
                'id' => $e->id,
                'mid' => $e->merchantMid?->mid,
                'currency' => $e->currency,
                'amount' => (string) abs((float) $e->amount),
                'note' => $e->note,
            ]) : [],
        ]);
    }

    /**
     * Draft edits: rates, wallet, notes.
     */
    public function update(Request $request, Settlement $settlement): RedirectResponse
    {
        $data = $request->validate([
            'rates' => ['array'],
            'rates.*' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'wallet_id' => ['nullable', Rule::exists('merchant_crypto_wallets', 'id')->where('merchant_id', $settlement->merchant_id)->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->assertDraft($settlement);

        $settlement->update(['wallet_id' => $data['wallet_id'] ?? null, 'notes' => $data['notes'] ?? null]);
        $this->settlements->setRates($settlement, array_filter($data['rates'] ?? [], fn ($r) => $r !== null && $r !== ''));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Settlement updated.']);

        return back();
    }

    public function addReports(Request $request, Settlement $settlement): RedirectResponse
    {
        $data = $request->validate([
            'report_ids' => ['array'],
            'report_ids.*' => ['integer'],
            'release_ids' => ['array'],
            'release_ids.*' => ['integer'],
        ]);
        $this->assertDraft($settlement);

        $this->settlements->addReports($settlement, DailyReportTask::query()->whereIn('id', $data['report_ids'] ?? [])->get());
        $releases = $this->settlements->availableReleases($settlement->merchant)->whereIn('id', $data['release_ids'] ?? []);
        foreach ($releases as $release) {
            $this->settlements->addRelease($settlement, $release);
        }
        $this->settlements->recalculate($settlement);

        return back();
    }

    public function addAdjustment(Request $request, Settlement $settlement): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-100000000,100000000'],
        ]);

        $this->settlements->addAdjustment($settlement, $data['description'], $data['currency'], (string) $data['amount']);

        return back();
    }

    public function removeLine(Settlement $settlement, SettlementLine $line): RedirectResponse
    {
        abort_unless($line->settlement_id === $settlement->id, 404);
        $this->settlements->removeLine($line);

        return back();
    }

    public function approve(Request $request, Settlement $settlement): RedirectResponse
    {
        $this->settlements->approve($settlement, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$settlement->number} approved."]);

        return back();
    }

    public function settle(Request $request, Settlement $settlement): RedirectResponse
    {
        $data = $request->validate([
            'tx_hash' => ['required', 'string', 'max:128'],
            'proof' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:10240'],
        ]);

        $proof = $request->file('proof')?->store("settlements/{$settlement->id}", 'local');
        $this->settlements->markSettled($settlement, $request->user(), trim($data['tx_hash']), $proof ?: null);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$settlement->number} marked as paid."]);

        return back();
    }

    public function cancel(Request $request, Settlement $settlement): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->settlements->cancel($settlement, $request->user(), $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$settlement->number} cancelled; its reports are free again."]);

        return back();
    }

    public function pdf(Settlement $settlement, SettlementStatementPdf $pdf): HttpResponse
    {
        AuditLogger::log('settlement.pdf', $settlement);

        return response($pdf->render($settlement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$settlement->number.'.pdf"',
        ]);
    }

    public function proof(Settlement $settlement): StreamedResponse
    {
        abort_unless($settlement->proof_path && Storage::disk('local')->exists($settlement->proof_path), 404);

        return Storage::disk('local')->download($settlement->proof_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Settlement $s): array
    {
        return [
            'id' => $s->id,
            'number' => $s->number,
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'merchant' => ['public_id' => $s->merchant->public_id, 'name' => $s->merchant->name],
            'payout_currency' => $s->payout_currency,
            'total_payout' => $s->total_payout,
            'lines_count' => $s->lines_count ?? $s->lines()->count(),
            'created_at' => $s->created_at?->toIso8601String(),
            'settled_at' => $s->settled_at?->toIso8601String(),
            'tx_hash' => $s->tx_hash,
        ];
    }

    private function assertDraft(Settlement $settlement): void
    {
        if (! $settlement->isEditable()) {
            throw ValidationException::withMessages(['settlement' => 'Only a draft settlement can be changed.']);
        }
    }
}
