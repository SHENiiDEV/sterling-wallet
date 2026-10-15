<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Models\MerchantMid;
use App\Models\Provider;
use App\Reports\Ingestion\ReportIngestionService;
use App\Reports\Pending\PendingReportFinder;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BotReportController extends Controller
{
    /**
     * Missing report dates per MID for one provider. Each MID is a "company"
     * entry so the existing bot loops over it unchanged: `id` is what the
     * provider portal calls the MID (gateway account or MID), and
     * `merchant_wallet_id` is our MID id to send back on upload.
     */
    public function pending(Request $request, PendingReportFinder $finder): JsonResponse
    {
        $request->validate(['provider' => ['required', 'string']]);
        $provider = Provider::query()->where('code', $request->string('provider'))->where('is_active', true)->firstOrFail();

        $companies = $finder->find($provider)->flatMap(function (array $entry) use ($provider) {
            /** @var MerchantMid $mid */
            $mid = $entry['mid'];
            $mid->loadMissing('merchant:id,name');
            $isGate = $mid->gate_provider_id === $provider->id;

            // A gateway MID may front several accounts (commerces): one entry each.
            $ids = $isGate ? ($mid->gateAccounts() ?: [$mid->mid]) : [$mid->mid];

            return array_map(fn (string $id) => [
                'name' => $mid->merchant->name.' · '.$mid->mid,
                'id' => $id,
                'mid' => $mid->mid,
                'mid_id' => $mid->id,
                'merchant_wallet_id' => $mid->id,
                'merchantId' => $mid->id,
                'currency' => $mid->currency->value,
                'role' => $isGate ? ProviderType::Gate->value : ProviderType::Bank->value,
                'pending_reports' => array_map(fn (ReportPeriod $p) => [
                    'report_date' => $p->reportDate->toDateString(),
                    'from' => $p->from->toDateString(),
                    'to' => $p->to->toDateString(),
                    'date_range' => ['from' => $p->from->toDateString(), 'to' => $p->to->toDateString()],
                ], $entry['periods']),
            ], $ids);
        });

        return response()->json([
            'provider' => $provider->code,
            'timezone' => $provider->timezone,
            'companies' => $companies->values(),
        ]);
    }

    public function upload(Request $request, Provider $provider, ReportIngestionService $ingestion): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400'],
            'report_date' => ['nullable', 'date'],
            'merchant_id' => ['nullable', 'integer'],
        ]);

        $covered = isset($data['merchant_id'])
            ? $provider->servedMids()->whereKey($data['merchant_id'])->get()
            : collect();

        try {
            $result = $ingestion->ingest(
                $provider,
                $request->file('file'),
                isset($data['report_date']) ? CarbonImmutable::parse($data['report_date']) : null,
                coveredMids: $covered,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'ok', ...$result->toArray()]);
    }

    /**
     * The legacy bot posts Cardaq chargeback files here. They are kept as
     * received for now: chargebacks are not applied to reports until their
     * file layout and transaction codes are known (STERLING_CHARGEBACK_TRN_TYPES).
     */
    public function chargeback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400'],
            'report_date' => ['nullable', 'date'],
            'merchant_id' => ['nullable', 'integer'],
        ]);

        $date = isset($data['report_date']) ? CarbonImmutable::parse($data['report_date'])->toDateString() : 'undated';
        $file = $request->file('file');
        $name = now()->format('His').'_'.Str::limit(preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName()) ?? 'chargeback', 100, '');
        $path = $file->storeAs("reports/raw/chargebacks/{$date}", $name, config('sterling.reports.disk'));

        return response()->json([
            'status' => 'ok',
            'file' => $path,
            'report_date' => $date === 'undated' ? null : $date,
            'processed' => false,
            'message' => 'Chargeback file stored; it is not applied to reports yet.',
        ]);
    }

    public function import(Request $request, ReportIngestionService $ingestion): JsonResponse
    {
        $provider = Provider::query()->where('code', $request->input('provider', 'cardaq'))->firstOrFail();

        return $this->upload($request, $provider, $ingestion);
    }
}
