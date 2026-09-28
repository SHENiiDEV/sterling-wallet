<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankHoliday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankHolidayController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) $request->integer('year', now()->year);

        return Inertia::render('admin/bank-holidays/index', [
            'year' => $year,
            'holidays' => BankHoliday::query()
                ->whereYear('date', $year)
                ->orderBy('date')
                ->get()
                ->map(fn (BankHoliday $holiday) => [
                    'id' => $holiday->id,
                    'date' => $holiday->date->toDateString(),
                    'name' => $holiday->name,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'unique:bank_holidays,date'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        BankHoliday::query()->create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Holiday added.']);

        return back();
    }

    public function destroy(BankHoliday $bankHoliday): RedirectResponse
    {
        $bankHoliday->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Holiday removed.']);

        return back();
    }
}
