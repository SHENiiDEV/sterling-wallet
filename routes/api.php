<?php

use App\Http\Controllers\Api\BotReportController;
use App\Http\Controllers\Api\TransferReportController;
use Illuminate\Support\Facades\Route;

/*
| External report bot (Node / Playwright). Same contract as before, but one
| upload endpoint serves every provider: /reports/upload/{provider code}.
*/
Route::prefix('v1')->middleware(['bot.token', 'throttle:120,1'])->group(function () {
    Route::get('reports/pending-tasks', [BotReportController::class, 'pending'])->name('api.reports.pending');
    // Bank-transfer (C2B) bots that cannot be scheduled (captcha) push a JSON batch here.
    Route::post('transfers/{provider:code}', [TransferReportController::class, 'upload'])->name('api.transfers.upload');
    Route::post('reports/upload/chargeback', [BotReportController::class, 'chargeback'])->name('api.reports.chargeback');
    Route::post('reports/upload/{provider:code}', [BotReportController::class, 'upload'])->name('api.reports.upload');
    // Legacy: the old bot posts Cardaq clearing CSVs here.
    Route::post('transactions/import', [BotReportController::class, 'import'])->name('api.transactions.import');
});
