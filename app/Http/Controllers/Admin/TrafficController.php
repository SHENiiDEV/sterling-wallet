<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Reports\CardTraffic;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal control: the share of first-time cards in each merchant's traffic.
 */
class TrafficController extends Controller
{
    public function index(Request $request, CardTraffic $traffic): Response
    {
        $data = $request->validate([
            'days' => ['nullable', 'in:7,14,30,60,90'],
            'merchant' => ['nullable', 'string', 'exists:merchants,public_id'],
        ]);

        $days = (int) ($data['days'] ?? 14);
        $to = CarbonImmutable::now(config('sterling.timezone'))->startOfDay();
        $from = $to->subDays($days - 1);

        $selected = isset($data['merchant']) ? Merchant::query()->where('public_id', $data['merchant'])->first(['id', 'public_id', 'name']) : null;

        return Inertia::render('admin/traffic/index', [
            'days' => $days,
            'limit' => (float) config('sterling.traffic.new_card_share_limit'),
            'warmup' => (int) config('sterling.traffic.warmup_days'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'merchants' => $traffic->merchants($from, $to),
            'selected' => $selected ? [
                'public_id' => $selected->public_id,
                'name' => $selected->name,
                'days' => $traffic->daily($from, $to, $selected->id)->map(fn (array $d) => Arr::except($d, ['merchant_id']))->sortByDesc('day')->values(),
            ] : null,
        ]);
    }
}
