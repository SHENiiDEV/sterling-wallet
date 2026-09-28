<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Profit\ProfitQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Providers & profit: margin per provider pair, card and flow analytics.
 */
class ProfitController extends Controller
{
    public function index(Request $request, ProfitQuery $profit): Response
    {
        [$from, $to] = self::period($request);
        [$prevFrom, $prevTo] = ProfitQuery::previous($from, $to);

        return Inertia::render('admin/profit/index', [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previousPeriod' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],
            'baseCurrency' => config('sterling.base_currency'),
            'summary' => $profit->summary($from, $to),
            'previous' => $profit->summary($prevFrom, $prevTo),
            'daily' => $profit->daily($from, $to),
            'byCurrency' => $profit->byCurrency($from, $to),
            'byPair' => $profit->byProviderPair($from, $to),
            'byMerchant' => $profit->byMerchant($from, $to, 25),
            'breakdowns' => $profit->breakdowns($from, $to),
        ]);
    }

    /**
     * `from`/`to` from the query, defaulting to the current month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $today = CarbonImmutable::now(config('sterling.timezone'))->startOfDay();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : $today->startOfMonth();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->startOfDay() : $today;

        // Keep the chart readable and the queries bounded.
        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366);
        }

        return [$from, $to];
    }
}
