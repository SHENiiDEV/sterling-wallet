<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProfitShareBase;
use App\Enums\StatementStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MonthlyStatement;
use App\Models\MonthlyStatementLine;
use App\Models\ProfitPartner;
use App\Models\ProfitShareRule;
use App\Profit\ProfitShareCalculator;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfitShareController extends Controller
{
    public function __construct(private ProfitShareCalculator $calculator) {}

    public function index(Request $request): Response
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month']
            ?? CarbonImmutable::now(config('sterling.timezone'))->format('Y-m');

        $statement = MonthlyStatement::query()->where('month', $month)->first();
        // A draft always shows fresh numbers; a closed month is frozen.
        if ($statement === null || $statement->status === StatementStatus::Draft) {
            $statement = $this->calculator->calculate($month);
        }
        $statement->load('lines', 'closer:id,name');

        return Inertia::render('admin/profit-share/index', [
            'month' => $month,
            'baseCurrency' => config('sterling.base_currency'),
            'statement' => [
                'id' => $statement->id,
                'month' => $statement->month,
                'status' => $statement->status->value,
                'turnover' => $statement->turnover,
                'net_profit' => $statement->net_profit,
                'shares_total' => $statement->shares_total,
                'company_remainder' => $statement->company_remainder,
                'merchants' => $statement->merchants ?? [],
                'closed_at' => $statement->closed_at?->toIso8601String(),
                'closer' => $statement->closer?->name,
                'calculated_at' => $statement->calculated_at?->toIso8601String(),
                'lines' => $statement->lines->map(fn (MonthlyStatementLine $l) => [
                    'id' => $l->id,
                    'partner' => $l->partner_name,
                    'partner_id' => $l->profit_partner_id,
                    'merchant' => $l->merchant_name,
                    'base' => $l->base->value,
                    'base_amount' => $l->base_amount,
                    'percent' => $l->percent,
                    'share' => $l->share,
                ]),
            ],
            'statements' => MonthlyStatement::query()->orderByDesc('month')->limit(24)
                ->get(['id', 'month', 'status', 'net_profit', 'shares_total', 'company_remainder'])
                ->map(fn (MonthlyStatement $s) => [
                    'month' => $s->month,
                    'status' => $s->status->value,
                    'net_profit' => $s->net_profit,
                    'shares_total' => $s->shares_total,
                    'company_remainder' => $s->company_remainder,
                ]),
            'partners' => ProfitPartner::query()->with('rules.merchant:id,name')->orderBy('name')->get()->map(fn (ProfitPartner $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'email' => $p->email,
                'is_active' => $p->is_active,
                'notes' => $p->notes,
                'rules' => $p->rules->sortBy('valid_from')->values()->map(fn (ProfitShareRule $r) => [
                    'id' => $r->id,
                    'merchant_id' => $r->merchant_id,
                    'merchant' => $r->merchant?->name,
                    'base' => $r->base->value,
                    'percent' => $r->percent,
                    'valid_from' => $r->valid_from->toDateString(),
                    'valid_to' => $r->valid_to?->toDateString(),
                    'notes' => $r->notes,
                ]),
            ]),
            'merchants' => Merchant::query()->orderBy('name')->get(['id', 'name']),
            'bases' => ProfitShareBase::options(),
        ]);
    }

    public function storePartner(Request $request): RedirectResponse
    {
        $partner = ProfitPartner::query()->create($this->validatePartner($request));
        AuditLogger::log('profit_partner.created', $partner);

        return back();
    }

    public function updatePartner(Request $request, ProfitPartner $partner): RedirectResponse
    {
        $partner->update($this->validatePartner($request));
        AuditLogger::log('profit_partner.updated', $partner);

        return back();
    }

    public function destroyPartner(ProfitPartner $partner): RedirectResponse
    {
        AuditLogger::log('profit_partner.deleted', $partner, ['name' => $partner->name]);
        $partner->delete();

        return back();
    }

    public function storeRule(Request $request, ProfitPartner $partner): RedirectResponse
    {
        $rule = $partner->rules()->create($this->validateRule($request));
        AuditLogger::log('profit_rule.created', $rule, $rule->only(['merchant_id', 'base', 'percent', 'valid_from', 'valid_to']));

        return back();
    }

    public function updateRule(Request $request, ProfitShareRule $rule): RedirectResponse
    {
        $rule->update($this->validateRule($request));
        AuditLogger::log('profit_rule.updated', $rule, $rule->only(['merchant_id', 'base', 'percent', 'valid_from', 'valid_to']));

        return back();
    }

    public function destroyRule(ProfitShareRule $rule): RedirectResponse
    {
        AuditLogger::log('profit_rule.deleted', $rule, $rule->only(['profit_partner_id', 'merchant_id', 'percent']));
        $rule->delete();

        return back();
    }

    public function close(Request $request, string $month): RedirectResponse
    {
        $statement = MonthlyStatement::query()->where('month', $month)->firstOrFail();
        $this->calculator->close($statement, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$month} closed. It will not be recalculated."]);

        return back();
    }

    public function reopen(Request $request, string $month): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only a super admin can reopen a closed month.');
        $statement = MonthlyStatement::query()->where('month', $month)->firstOrFail();
        $this->calculator->reopen($statement, $request->user());

        return back();
    }

    public function pdf(string $month): HttpResponse
    {
        $statement = MonthlyStatement::query()->with('lines')->where('month', $month)->firstOrFail();

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('profit.statement', ['statement' => $statement])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return response((string) $pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="profit-share-'.$month.'.pdf"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePartner(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request): array
    {
        $data = $request->validate([
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'base' => ['required', Rule::enum(ProfitShareBase::class)],
            'percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Rules may not change what a closed month already paid out.
        $closed = MonthlyStatement::query()->where('status', StatementStatus::Closed)
            ->where('month', '>=', CarbonImmutable::parse($data['valid_from'])->format('Y-m'))
            ->orderBy('month')->value('month');
        if ($closed !== null) {
            throw ValidationException::withMessages(['valid_from' => "{$closed} is closed; start the rule after it."]);
        }

        return $data;
    }
}
