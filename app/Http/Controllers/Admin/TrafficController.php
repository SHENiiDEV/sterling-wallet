<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Reports\CardTraffic;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal control of the traffic: first-time cards, BIN concentration, repeated attempts and night sales,
 * per merchant or per MID.
 */
class TrafficController extends Controller
{
    public function index(Request $request, CardTraffic $traffic): Response
    {
        $data = $request->validate([
            'days' => ['nullable', 'in:7,14,30,60,90'],
            'by' => ['nullable', 'in:merchant,mid'],
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
            ] : null,
        ]);
    }
}
