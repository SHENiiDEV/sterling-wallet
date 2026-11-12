<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClientRequest;
use App\Mail\ClientDailyReportMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\User;
use App\Reports\Generation\ClientCardLists;
use App\Reports\Generation\ClientDailyStatement;
use App\Reports\Generation\ClientDays;
use App\Reports\Generation\ClientTrafficReport;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/clients/index', [
            'clients' => Client::query()
                ->with('companies:id,client_id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'invoice_name' => $client->invoice_name,
                    'invoice_address' => $client->invoice_address,
                    'invoice_email' => $client->invoice_email,
                    'notes' => $client->notes,
                    'companies' => $client->companies->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
                ]),
            'defaultDate' => now()->subDay()->toDateString(),
        ]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        $client = Client::query()->create($request->validated());
        AuditLogger::log('client.created', $client);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Client created. Assign its companies on the Companies page.']);

        return back();
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Client saved.']);

        return back();
    }

    public function destroy(Client $client): RedirectResponse
    {
        // Its companies stay, just without a client (foreign key: null on delete).
        $client->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Client deleted.']);

        return back();
    }

    /**
     * Inside a client: its companies and, day by day, what each of them did.
     */
    public function show(Request $request, Client $client, ClientDays $days): Response
    {
        $client->load('companies:id,client_id,name,parent_id');
        $merchants = Merchant::query()->whereIn('company_id', $client->companies->pluck('id'))->with('mids:id,merchant_id,mid,currency,status')->orderBy('name')->get();

        // The month asked for, else the latest one with reports.
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? (string) $request->query('month')
            : ($days->latestMonth($client) ?? now()->format('Y-m'));

        return Inertia::render('admin/clients/show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'invoice_name' => $client->invoice_name,
                'invoice_address' => $client->invoice_address,
                'invoice_email' => $client->invoice_email,
                'notes' => $client->notes,
            ],
            'companies' => $client->companies->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'merchants' => $merchants->where('company_id', $company->id)->map(fn (Merchant $m) => [
                    'public_id' => $m->public_id,
                    'name' => $m->name,
                    'mids' => $m->mids->map(fn ($mid) => ['mid' => $mid->mid, 'currency' => $mid->currency->value, 'status' => $mid->status->value])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'month' => $month,
            'days' => $days->forMonth($client, $month),
            'portalUsers' => User::query()->where('client_id', $client->id)->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->is_active,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Consolidated daily report as PDF or XLSX.
     */
    public function report(Request $request, Client $client, ClientDailyStatement $statement): HttpResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            // A period statement (a week, a month, any range up to a year): the last report day.
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'format' => ['sometimes', 'in:pdf,xlsx'],
            'company' => ['nullable', 'integer'],
        ]);
        $date = CarbonImmutable::parse($data['date']);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : null;
        $isPeriod = $to !== null && $to->greaterThan($date);
        if ($isPeriod && $date->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => 'A period can be at most a year.']);
        }
        $xlsx = ($data['format'] ?? 'pdf') === 'xlsx';
        $companyId = isset($data['company']) ? (int) $data['company'] : null;
        $company = $companyId === null ? null : Company::query()->where('client_id', $client->id)->findOrFail($companyId);
        $name = ($isPeriod ? 'report_' : 'daily-report_').preg_replace('/[^A-Za-z0-9_-]/', '_', $client->invoiceName().($company ? '_'.$company->name : '')).'_'.$data['date'].($isPeriod ? '_'.$data['to'] : '');

        return response($xlsx ? $statement->xlsx($client, $date, $companyId, $to) : $statement->render($client, $date, $companyId, $to), 200, [
            'Content-Type' => $xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.($xlsx ? '.xlsx' : '.pdf').'"',
        ]);
    }

    /**
     * Traffic of the client over a period (attempts, approval, decline reasons) as PDF or XLSX.
     */
    public function traffic(Request $request, Client $client, ClientTrafficReport $traffic): HttpResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date'],
            'format' => ['sometimes', 'in:pdf,xlsx'],
            'company' => ['nullable', 'integer'],
        ]);
        $from = CarbonImmutable::parse($data['date']);
        $to = CarbonImmutable::parse($data['to']);
        if ($from->diffInDays($to) > 92) {
            throw ValidationException::withMessages(['to' => 'A traffic report covers at most three months.']);
        }
        $xlsx = ($data['format'] ?? 'pdf') === 'xlsx';
        $companyId = isset($data['company']) ? (int) $data['company'] : null;
        $company = $companyId === null ? null : Company::query()->where('client_id', $client->id)->findOrFail($companyId);
        $name = 'traffic_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $client->invoiceName().($company ? '_'.$company->name : '')).'_'.$data['date'].'_'.$data['to'];

        return response($xlsx ? $traffic->xlsx($client, $from, $to, $companyId) : $traffic->render($client, $from, $to, $companyId), 200, [
            'Content-Type' => $xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.($xlsx ? '.xlsx' : '.pdf').'"',
        ]);
    }

    /**
     * The client's cards as masks (123456*7890) for white and black lists: an XLSX with every list, or one list as CSV.
     */
    public function cards(Request $request, Client $client, ClientCardLists $cards): HttpResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date'],
            'format' => ['sometimes', 'in:xlsx,csv'],
            'list' => ['sometimes', Rule::in(ClientCardLists::lists())],
            'company' => ['nullable', 'integer'],
        ]);
        $from = CarbonImmutable::parse($data['date']);
        $to = CarbonImmutable::parse($data['to']);
        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => 'A card list covers at most a year.']);
        }
        $companyId = isset($data['company']) ? (int) $data['company'] : null;
        $result = $cards->data($client, $from, $to, $companyId);
        $csv = ($data['format'] ?? 'xlsx') === 'csv';
        $list = $data['list'] ?? 'all';
        AuditLogger::log('client.cards_exported', $client, ['from' => $data['date'], 'to' => $data['to'], 'list' => $csv ? $list : 'all', 'cards' => count($result['cards'])]);
        $name = 'cards_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $client->invoiceName()).($csv ? '_'.$list : '').'_'.$data['date'].'_'.$data['to'];

        return response($csv ? $cards->csv($result, $list) : $cards->xlsx($result), 200, [
            'Content-Type' => $csv ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.($csv ? '.csv' : '.xlsx').'"',
        ]);
    }

    public function send(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        if (! $client->invoice_email) {
            throw ValidationException::withMessages(['client' => 'Add an invoice e-mail to the client first.']);
        }

        Mail::to($client->invoice_email)->queue(new ClientDailyReportMail($client, $data['date']));
        AuditLogger::log('client.report_sent', $client, ['date' => $data['date'], 'to' => $client->invoice_email]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Report sent to {$client->invoice_email}."]);

        return back();
    }
}
