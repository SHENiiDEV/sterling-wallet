<?php

use App\Http\Controllers\Api\BotReportController;
use Illuminate\Support\Facades\Route;

/*
| External report bot (Node / Playwright). Same contract as before, but one
| upload endpoint serves every provider: /reports/upload/{provider code}.
*/
Route::prefix('v1')->middleware(['bot.token', 'throttle:120,1'])->group(function () {
    Route::get('reports/pending-tasks', [BotReportController::class, 'pending'])->name('api.reports.pending');
    Route::post('reports/upload/{provider:code}', [BotReportController::class, 'upload'])->name('api.reports.upload');
    // Legacy: the old bot posts Cardaq clearing CSVs here.
    Route::post('transactions/import', [BotReportController::class, 'import'])->name('api.transactions.import');
});
