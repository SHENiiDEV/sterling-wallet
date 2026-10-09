<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Enums\MerchantStatus;
use App\Enums\OfferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CommercialOfferRequest;
use App\Models\CommercialOffer;
use App\Models\Company;
use App\Models\Merchant;
use App\Offers\OfferProposal;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CommercialOfferController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OfferStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        // Sent offers past their validity date expire on their own.
        CommercialOffer::query()->where('status', OfferStatus::Sent)
            ->whereNotNull('valid_until')->where('valid_until', '<', now()->toDateString())
            ->update(['status' => OfferStatus::Expired]);

        return Inertia::render('admin/offers/index', [
            'offers' => CommercialOffer::query()
                ->with('merchant:id,public_id,name', 'creator:id,name')
                ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
                ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q->where(fn (Builder $q) => $q
                    ->where('company_name', 'like', "%{$s}%")->orWhere('number', $s)->orWhere('contact_email', 'like', "%{$s}%")))
                ->latest('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (CommercialOffer $o) => $this->present($o)),
            'statusCounts' => CommercialOffer::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'filters' => (object) $filters,
            'statuses' => OfferStatus::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/offers/form', ['offer' => null, 'currencies' => Currency::options()]);
    }

    public function store(CommercialOfferRequest $request): RedirectResponse
    {
        $offer = DB::transaction(function () use ($request) {
            $offer = CommercialOffer::query()->create([
                ...$request->validated(),
                'number' => 'OFF-TMP-'.uniqid(),
                'status' => OfferStatus::Draft,
                'created_by' => $request->user()->id,
            ]);
            $offer->update(['number' => sprintf('OFF-%s-%04d', now()->format('Y'), $offer->id)]);

            return $offer;
        });
        AuditLogger::log('offer.created', $offer);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Offer {$offer->number} created."]);

        return to_route('admin.offers.edit', $offer);
    }

    public function edit(CommercialOffer $offer): Response
    {
        $offer->load('merchant:id,public_id,name', 'creator:id,name');

        return Inertia::render('admin/offers/form', [
            'offer' => [...$this->present($offer), ...$offer->only([
                'contact_name', 'contact_email', 'country', 'website', 'mcc', 'currencies', 'expected_monthly_volume',
                ...CommercialOffer::TARIFF_FIELDS, 'setup_fee', 'rolling_reserve_cap', 'settlement_terms', 'fee_currency', 'extra_fees', 'intro', 'terms', 'notes',
            ]), 'valid_until' => $offer->valid_until?->toDateString()],
            'currencies' => Currency::options(),
        ]);
    }

    public function update(CommercialOfferRequest $request, CommercialOffer $offer): RedirectResponse
    {
        if (in_array($offer->status, [OfferStatus::Accepted, OfferStatus::Declined], true)) {
            throw ValidationException::withMessages(['offer' => 'A decided offer can no longer be edited.']);
        }

        $offer->update($request->validated());
        AuditLogger::log('offer.updated', $offer);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Offer saved.']);

        return back();
    }

    public function destroy(CommercialOffer $offer): RedirectResponse
    {
        if ($offer->status !== OfferStatus::Draft) {
            throw ValidationException::withMessages(['offer' => 'Only a draft can be deleted; decline it instead.']);
        }
        AuditLogger::log('offer.deleted', $offer, ['number' => $offer->number]);
        $offer->delete();

        return to_route('admin.offers.index');
    }

    public function status(Request $request, CommercialOffer $offer): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([OfferStatus::Sent->value, OfferStatus::Declined->value, OfferStatus::Draft->value])]]);
        $to = OfferStatus::from($data['status']);

        if ($offer->status === OfferStatus::Accepted) {
            throw ValidationException::withMessages(['offer' => 'This offer is already accepted.']);
        }

        $offer->update([
            'status' => $to,
            'sent_at' => $to === OfferStatus::Sent ? now() : $offer->sent_at,
            'decided_at' => $to === OfferStatus::Declined ? now() : null,
        ]);
        AuditLogger::log('offer.status', $offer, ['status' => $to->value]);

        return back();
    }

    /**
     * Accept: the prospect becomes a merchant (onboarding) with the offered tariff.
     */
    public function accept(Request $request, CommercialOffer $offer): RedirectResponse
    {
        if ($offer->status === OfferStatus::Accepted) {
            return to_route('admin.merchants.show', $offer->merchant);
        }

        $merchant = DB::transaction(function () use ($offer, $request) {
            $company = Company::query()->firstOrCreate(['name' => $offer->company_name], ['country' => $offer->country]);
            $merchant = Merchant::query()->create([
                'company_id' => $company->id,
                'name' => $offer->company_name,
                'status' => MerchantStatus::Onboarding,
                'mcc' => $offer->mcc,
                'invoice_email' => $offer->contact_email,
                'notes' => "Created from offer {$offer->number}.",
                ...$offer->only([...CommercialOffer::TARIFF_FIELDS, 'rolling_reserve_cap', 'settlement_terms']),
            ]);
            $offer->update(['status' => OfferStatus::Accepted, 'decided_at' => now(), 'merchant_id' => $merchant->id]);
            AuditLogger::log('offer.accepted', $offer, ['merchant' => $merchant->public_id, 'by' => $request->user()->id]);

            return $merchant;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "Merchant {$merchant->name} created from {$offer->number}. Add its MIDs next."]);

        return to_route('admin.merchants.show', $merchant);
    }

    public function pdf(CommercialOffer $offer, OfferProposal $proposal): HttpResponse
    {
        return response($proposal->render($offer), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$offer->number.'.pdf"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CommercialOffer $o): array
    {
        return [
            'id' => $o->id,
            'number' => $o->number,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'company_name' => $o->company_name,
            'contact_email' => $o->contact_email,
            'currencies' => $o->currencies ?? [],
            'valid_until' => $o->valid_until?->toDateString(),
            'merchant' => $o->merchant ? ['public_id' => $o->merchant->public_id, 'name' => $o->merchant->name] : null,
            'creator' => $o->creator?->name,
            'created_at' => $o->created_at?->toIso8601String(),
            'sent_at' => $o->sent_at?->toIso8601String(),
        ];
    }
}
