<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Reports\CardTraffic;
use App\Reports\Generation\ClientTrafficReport;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Internal control of the traffic: first-time cards, BIN concentration, repeated attempts and night sales,
 * per merchant or per MID.
 */
class TrafficController extends Controller
{
    /**
     * A traffic report from an uploaded Corefy payments export: upload it, get the file. Nothing is stored.
     */
    public function fromFile(Request $request, ClientTrafficReport $report): HttpResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:51200'],
            'title' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['sometimes', 'in:pdf,xlsx'],
        ]);

        try {
            $result = $report->dataFromFile($request->file('file'), $data['title'] ?? null, isset($data['from']) ? CarbonImmutable::parse($data['from']) : null, isset($data['to']) ? CarbonImmutable::parse($data['to']) : null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        $xlsx = ($data['format'] ?? 'pdf') === 'xlsx';
        AuditLogger::log('traffic.report_from_file', null, ['title' => $result['title'], 'attempts' => $result['totals']['attempts']]);
        $name = 'traffic_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $result['title']).'_'.$result['from']->toDateString().'_'.$result['to']->toDateString();

        return response($xlsx ? $report->xlsxData($result) : $report->renderData($result), 200, [
            'Content-Type' => $xlsx ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.($xlsx ? '.xlsx' : '.pdf').'"',
        ]);
    }

    public function index(Request $request, CardTraffic $traffic): Response
    {
        $data = $request->validate([
            'days' => ['nullable', 'in:7,14,30,60,90'],
            'by' => ['nullable', 'in:merchant,mid,client'],
            'client' => ['nullable', 'integer', 'exists:clients,id'],
            'merchant' => ['nullable', 'string', 'exists:merchants,public_id'],
            'mid' => ['nullable', 'string', 'exists:merchant_mids,mid'],
        ]);

        $days = (int) ($data['days'] ?? 14);
        $by = $data['by'] ?? CardTraffic::BY_MERCHANT;
        $to = CarbonImmutable::now(config('sterling.timezone'))->startOfDay();
        $from = $to->subDays($days - 1);

        $selected = null;
        if ($by === CardTraffic::BY_MID && isset($data['mid'])) {
            $mid = MerchantMid::query()->with('merchant:id,name')->where('mid', $data['mid'])->first();
            $selected = $mid ? ['key' => $mid->mid, 'name' => "{$mid->merchant?->name} · MID {$mid->mid}", 'id' => $mid->id] : null;
        } elseif ($by === CardTraffic::BY_CLIENT && isset($data['client'])) {
            $client = Client::query()->find($data['client'], ['id', 'name']);
            $selected = $client ? ['key' => (string) $client->id, 'name' => $client->name, 'id' => $client->id] : null;
        } elseif ($by === CardTraffic::BY_MERCHANT && isset($data['merchant'])) {
            $merchant = Merchant::query()->where('public_id', $data['merchant'])->first(['id', 'public_id', 'name']);
            $selected = $merchant ? ['key' => $merchant->public_id, 'name' => $merchant->name, 'id' => $merchant->id] : null;
        }

        return Inertia::render('admin/traffic/index', [
            'days' => $days,
            'by' => $by,
            'limit' => (float) config('sterling.traffic.new_card_share_limit'),
            'warmup' => (int) config('sterling.traffic.warmup_days'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'owners' => $traffic->owners($from, $to, $by),
            'selected' => $selected ? [
                'key' => $selected['key'],
                'name' => $selected['name'],
                'days' => $traffic->daily($from, $to, $selected['id'], $by)->map(fn (array $d) => Arr::except($d, ['owner_id']))->sortByDesc('day')->values(),
                // A client: every MID under it with its company and merchant, to see where the traffic comes from.
                'members' => $by === CardTraffic::BY_CLIENT ? $this->members($traffic, $from, $to, $selected['id']) : null,
            ] : null,
        ]);
    }

    /**
     * The MIDs under a client, with the company each belongs to.
     *
     * @return list<array<string, mixed>>
     */
    private function members(CardTraffic $traffic, CarbonImmutable $from, CarbonImmutable $to, int $clientId): array
    {
        $mids = MerchantMid::query()->with('merchant.company:id,name')
            ->whereHas('merchant.company', fn ($q) => $q->where('client_id', $clientId))
            ->get()->keyBy('mid');

        return $traffic->owners($from, $to, CardTraffic::BY_MID)
            ->filter(fn (array $o) => $mids->has($o['mid']))
            ->map(fn (array $o) => [...$o, 'company' => $mids[$o['mid']]->merchant?->company?->name])
            ->values()->all();
    }
}
