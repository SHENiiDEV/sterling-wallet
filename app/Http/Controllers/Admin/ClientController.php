<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClientRequest;
use App\Mail\ClientDailyReportMail;
use App\Models\Client;
use App\Reports\Generation\ClientDailyStatement;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Mail;
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
     * Consolidated daily report as PDF or XLSX.
     */
    public function report(Request $request, Client $client, ClientDailyStatement $statement): HttpResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'format' => ['sometimes', 'in:pdf,xlsx'],
        ]);
        $date = CarbonImmutable::parse($data['date']);
        $xlsx = ($data['format'] ?? 'pdf') === 'xlsx';
        $name = 'daily-report_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $client->name).'_'.$data['date'];

        return response($xlsx ? $statement->xlsx($client, $date) : $statement->render($client, $date), 200, [
            'Content-Type' => $xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.($xlsx ? '.xlsx' : '.pdf').'"',
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
