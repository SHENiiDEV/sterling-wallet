<?php

use App\Http\Controllers\Admin\BankHolidayController;
use App\Http\Controllers\Admin\BotController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentCommentController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\DocumentFileController;
use App\Http\Controllers\Admin\DocumentStatusChangeController;
use App\Http\Controllers\Admin\DocumentStatusController;
use App\Http\Controllers\Admin\FxRateController;
use App\Http\Controllers\Admin\MerchantController;
use App\Http\Controllers\Admin\MerchantMidController;
use App\Http\Controllers\Admin\MerchantWalletController;
use App\Http\Controllers\Admin\OperationController;
use App\Http\Controllers\Admin\ProviderController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::redirect('dashboard', '/admin');

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('merchants', MerchantController::class);
    Route::scopeBindings()->group(function () {
        Route::post('merchants/{merchant}/mids', [MerchantMidController::class, 'store'])->name('merchants.mids.store');
        Route::put('merchants/{merchant}/mids/{mid}', [MerchantMidController::class, 'update'])->name('merchants.mids.update');
        Route::delete('merchants/{merchant}/mids/{mid}', [MerchantMidController::class, 'destroy'])->name('merchants.mids.destroy');

        Route::post('merchants/{merchant}/wallets', [MerchantWalletController::class, 'store'])->name('merchants.wallets.store');
        Route::put('merchants/{merchant}/wallets/{wallet}', [MerchantWalletController::class, 'update'])->name('merchants.wallets.update');
        Route::delete('merchants/{merchant}/wallets/{wallet}', [MerchantWalletController::class, 'destroy'])->name('merchants.wallets.destroy');
        Route::post('merchants/{merchant}/wallets/{wallet}/seed', [MerchantWalletController::class, 'seed'])->name('merchants.wallets.seed');
    });

    Route::get('operations', [OperationController::class, 'index'])->name('operations.index');
    Route::get('operations/{operation}', [OperationController::class, 'show'])->name('operations.show');

    Route::get('bots', [BotController::class, 'index'])->name('bots.index');
    Route::post('bots/accounts', [BotController::class, 'store'])->name('bots.accounts.store');
    Route::put('bots/accounts/{account}', [BotController::class, 'update'])->name('bots.accounts.update');
    Route::delete('bots/accounts/{account}', [BotController::class, 'destroy'])->name('bots.accounts.destroy');
    Route::post('bots/accounts/{account}/run', [BotController::class, 'run'])->name('bots.accounts.run');
    Route::post('bots/runs/{run}/retry', [BotController::class, 'retry'])->name('bots.runs.retry');
    Route::get('bots/runs/{run}/screenshot', [BotController::class, 'screenshot'])->name('bots.runs.screenshot');

    Route::resource('companies', CompanyController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('providers', ProviderController::class)->except(['show']);
    Route::resource('fx-rates', FxRateController::class)->only(['index', 'store', 'destroy']);
    Route::resource('bank-holidays', BankHolidayController::class)->only(['index', 'store', 'destroy']);

    Route::resource('documents', DocumentController::class)->except(['create', 'edit']);
    Route::put('documents/{document}/status', DocumentStatusChangeController::class)->name('documents.status');
    Route::post('documents/{document}/comments', DocumentCommentController::class)->name('documents.comments.store');
    Route::post('documents/{document}/files', [DocumentFileController::class, 'store'])->name('documents.files.store');
    Route::get('documents/{document}/files/{file}', [DocumentFileController::class, 'show'])->name('documents.files.show')->scopeBindings();
    Route::delete('documents/{document}/files/{file}', [DocumentFileController::class, 'destroy'])->name('documents.files.destroy')->scopeBindings();

    Route::resource('document-statuses', DocumentStatusController::class)->only(['index', 'store', 'update', 'destroy']);
});

require __DIR__.'/settings.php';
