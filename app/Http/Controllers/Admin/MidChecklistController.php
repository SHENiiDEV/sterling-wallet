<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Merchants\MidReadiness;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One page for what is missing before merchants and terminals work smoothly.
 */
class MidChecklistController extends Controller
{
    public function index(Request $request, MidReadiness $readiness): Response
    {
        $filters = $request->validate(['issue' => ['nullable', 'string', 'in:'.implode(',', array_keys(MidReadiness::CODES))]]);

        $all = $readiness->merchants();
        $merchants = $all;

        if ($issue = $filters['issue'] ?? null) {
            $merchants = $all->map(fn (array $m) => [
                ...$m,
                'issues' => array_values(array_filter($m['issues'], fn (array $i) => $i['code'] === $issue)),
                'mids' => array_values(array_filter(array_map(fn (array $mid) => [
                    ...$mid,
                    'issues' => array_values(array_filter($mid['issues'], fn (array $i) => $i['code'] === $issue)),
                ], $m['mids']), fn (array $mid) => $mid['issues'] !== [])),
            ])->filter(fn (array $m) => $m['issues'] !== [] || $m['mids'] !== [])->values();
        }

        return Inertia::render('admin/checklist/index', [
            'merchants' => $merchants,
            'counts' => $readiness->counts($all),
            'codes' => MidReadiness::CODES,
            'filters' => (object) $filters,
        ]);
    }
}
