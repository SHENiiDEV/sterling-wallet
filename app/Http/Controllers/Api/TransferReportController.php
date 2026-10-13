<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MerchantMid;
use App\Models\Provider;
use App\Reports\Ingestion\ReportIngestionService;
use App\Reports\Parsers\LapkiPayParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use SplFileInfo;

/**
 * Batch of bank-transfer (C2B) transactions pushed by a bot, e.g. LapkiPay.
 * The provider must have the `lapkipay` report format; the batch is parsed
 * and ingested like any provider file, so reports build themselves.
 */
class TransferReportController extends Controller
{
    public function upload(Request $request, Provider $provider, ReportIngestionService $ingestion): JsonResponse
    {
        if ($provider->report_format !== LapkiPayParser::FORMAT) {
            return response()->json(['message' => "Provider {$provider->code} does not take transfer batches."], 422);
        }

        $data = $request->validate([
            'transactions' => ['required', 'array', 'max:5000'],
            'transactions.*' => ['array'],
            'transactions.*.id' => ['required', 'string', 'max:128'],
            'transactions.*.company' => ['required', 'string', 'max:255'],
            'transactions.*.amount' => ['required', 'numeric'],
            'transactions.*.currency' => ['required', 'string', 'size:3'],
            'transactions.*.status' => ['required', 'string', 'max:32'],
            'transactions.*.date' => ['required', 'string', 'max:32'],
            'balances' => ['sometimes', 'array', 'max:50'],
            'balances.*.company' => ['required', 'string', 'max:255'],
            'balances.*.currency' => ['required', 'string', 'size:3'],
            'balances.*.available' => ['required', 'numeric'],
            'balances.*.hold' => ['nullable', 'numeric'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'transfers').'.json';
        file_put_contents($path, json_encode(['transactions' => $data['transactions']]));

        try {
            $result = $ingestion->ingest($provider, new SplFileInfo($path));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            @unlink($path);
        }

        return response()->json([
            'status' => 'ok',
            ...$result->toArray(),
            'balances_updated' => $this->storeBalances($provider, $data['balances'] ?? []),
        ]);
    }

    /**
     * What the provider's cabinet showed: kept on the MID for reconciliation.
     *
     * @param  list<array<string, mixed>>  $balances
     */
    private function storeBalances(Provider $provider, array $balances): int
    {
        $updated = 0;

        foreach ($balances as $balance) {
            $mid = MerchantMid::query()
                ->where('bank_provider_id', $provider->id)
                ->where('mid', strtoupper(trim((string) $balance['company'])).' '.strtoupper((string) $balance['currency']))
                ->first();

            if ($mid !== null) {
                $mid->update([
                    'reported_balance' => $balance['available'],
                    'reported_hold' => $balance['hold'] ?? 0,
                    'balance_reported_at' => now(),
                ]);
                $updated++;
            }
        }

        return $updated;
    }
}
