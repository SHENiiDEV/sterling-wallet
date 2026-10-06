<?php

use App\Http\Controllers\Admin\BankHolidayController;
use App\Http\Controllers\Admin\BotController;
use App\Http\Controllers\Admin\CommercialOfferController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentCommentController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\DocumentFileController;
use App\Http\Controllers\Admin\DocumentStatusChangeController;
use App\Http\Controllers\Admin\DocumentStatusController;
use App\Http\Controllers\Admin\DocumentTemplateController;
use App\Http\Controllers\Admin\FxRateController;
use App\Http\Controllers\Admin\MerchantAcquirerController;
use App\Http\Controllers\Admin\MerchantController;
use App\Http\Controllers\Admin\MerchantMidController;
use App\Http\Controllers\Admin\MerchantWalletController;
use App\Http\Controllers\Admin\OperationController;
use App\Http\Controllers\Admin\PortalUserController;
use App\Http\Controllers\Admin\ProfitController;
use App\Http\Controllers\Admin\ProfitShareController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\ReportCenterController;
use App\Http\Controllers\Admin\SettlementController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Portal\PortalController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// After login: staff to the admin panel, merchant users to their portal.
Route::get('dashboard', fn () => auth()->user()?->isStaff() ? redirect()->route('admin.dashboard') : redirect()->route('portal.dashboard'))
    ->middleware('auth')->name('dashboard');

Route::middleware(['auth', 'merchant'])->prefix('merchant')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('reports', [PortalController::class, 'reports'])->name('reports');
    Route::get('reports/{report}/download/{file}', [PortalController::class, 'downloadReport'])->name('reports.download')->whereIn('file', ['pdf', 'xlsx', 'operations']);
    Route::get('settlements', [PortalController::class, 'settlements'])->name('settlements');
    Route::get('settlements/{settlement}/pdf', [PortalController::class, 'settlementPdf'])->name('settlements.pdf');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::middleware('module:merchants')->group(function () {
        Route::resource('merchants', MerchantController::class);
        Route::scopeBindings()->group(function () {
            Route::post('merchants/{merchant}/mids', [MerchantMidController::class, 'store'])->name('merchants.mids.store');
            Route::put('merchants/{merchant}/mids/{mid}', [MerchantMidController::class, 'update'])->name('merchants.mids.update');
            Route::delete('merchants/{merchant}/mids/{mid}', [MerchantMidController::class, 'destroy'])->name('merchants.mids.destroy');

            Route::post('merchants/{merchant}/wallets', [MerchantWalletController::class, 'store'])->name('merchants.wallets.store');
            Route::put('merchants/{merchant}/wallets/{wallet}', [MerchantWalletController::class, 'update'])->name('merchants.wallets.update');
            Route::delete('merchants/{merchant}/wallets/{wallet}', [MerchantWalletController::class, 'destroy'])->name('merchants.wallets.destroy');
            Route::post('merchants/{merchant}/wallets/{wallet}/seed', [MerchantWalletController::class, 'seed'])->name('merchants.wallets.seed');

            Route::post('merchants/{merchant}/acquirers', [MerchantAcquirerController::class, 'store'])->name('merchants.acquirers.store');
            Route::put('merchants/{merchant}/acquirers/{acquirer}', [MerchantAcquirerController::class, 'update'])->name('merchants.acquirers.update');
            Route::delete('merchants/{merchant}/acquirers/{acquirer}', [MerchantAcquirerController::class, 'destroy'])->name('merchants.acquirers.destroy');

        });
        Route::resource('companies', CompanyController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('companies/{company}/portal-users', [PortalUserController::class, 'store'])->name('companies.portal-users.store');
        Route::put('companies/{company}/portal-users/{user}', [PortalUserController::class, 'update'])->name('companies.portal-users.update');
        Route::delete('companies/{company}/portal-users/{user}', [PortalUserController::class, 'destroy'])->name('companies.portal-users.destroy');
    });

    Route::middleware('module:operations')->group(function () {
        Route::get('operations', [OperationController::class, 'index'])->name('operations.index');
        Route::get('operations/{operation}', [OperationController::class, 'show'])->name('operations.show');
    });

    Route::middleware('module:reports')->group(function () {
        Route::get('reports', [ReportCenterController::class, 'index'])->name('reports.index');
        Route::post('reports/upload', [ReportCenterController::class, 'upload'])->name('reports.upload');
        Route::get('reports/{report}', [ReportCenterController::class, 'show'])->name('reports.show');
        Route::post('reports/{report}/regenerate', [ReportCenterController::class, 'regenerate'])->name('reports.regenerate');
        Route::post('reports/{report}/resend', [ReportCenterController::class, 'resend'])->name('reports.resend');
        Route::delete('reports/{report}', [ReportCenterController::class, 'destroy'])->name('reports.destroy');
        Route::get('reports/{report}/download/{file}', [ReportCenterController::class, 'download'])->name('reports.download')->whereIn('file', ['pdf', 'xlsx', 'operations']);
    });

    Route::middleware('module:settlements')->group(function () {
        Route::get('settlements', [SettlementController::class, 'index'])->name('settlements.index');
        Route::post('settlements', [SettlementController::class, 'store'])->name('settlements.store');
        Route::get('settlements/{settlement}', [SettlementController::class, 'show'])->name('settlements.show');
        Route::put('settlements/{settlement}', [SettlementController::class, 'update'])->name('settlements.update');
        Route::post('settlements/{settlement}/lines', [SettlementController::class, 'addReports'])->name('settlements.lines.store');
        Route::post('settlements/{settlement}/adjustments', [SettlementController::class, 'addAdjustment'])->name('settlements.adjustments.store');
        Route::delete('settlements/{settlement}/lines/{line}', [SettlementController::class, 'removeLine'])->name('settlements.lines.destroy');
        Route::post('settlements/{settlement}/approve', [SettlementController::class, 'approve'])->name('settlements.approve');
        Route::post('settlements/{settlement}/settle', [SettlementController::class, 'settle'])->name('settlements.settle');
        Route::post('settlements/{settlement}/cancel', [SettlementController::class, 'cancel'])->name('settlements.cancel');
        Route::get('settlements/{settlement}/pdf', [SettlementController::class, 'pdf'])->name('settlements.pdf');
        Route::get('settlements/{settlement}/proof', [SettlementController::class, 'proof'])->name('settlements.proof');
    });

    Route::middleware('module:profit')->group(function () {
        Route::get('profit', [ProfitController::class, 'index'])->name('profit.index');
        Route::get('profit-share', [ProfitShareController::class, 'index'])->name('profit-share.index');
        Route::post('profit-share/partners', [ProfitShareController::class, 'storePartner'])->name('profit-share.partners.store');
        Route::put('profit-share/partners/{partner}', [ProfitShareController::class, 'updatePartner'])->name('profit-share.partners.update');
        Route::delete('profit-share/partners/{partner}', [ProfitShareController::class, 'destroyPartner'])->name('profit-share.partners.destroy');
        Route::post('profit-share/partners/{partner}/rules', [ProfitShareController::class, 'storeRule'])->name('profit-share.rules.store');
        Route::put('profit-share/rules/{rule}', [ProfitShareController::class, 'updateRule'])->name('profit-share.rules.update');
        Route::delete('profit-share/rules/{rule}', [ProfitShareController::class, 'destroyRule'])->name('profit-share.rules.destroy');
        Route::post('profit-share/{month}/close', [ProfitShareController::class, 'close'])->name('profit-share.close')->where('month', '\d{4}-\d{2}');
        Route::post('profit-share/{month}/reopen', [ProfitShareController::class, 'reopen'])->name('profit-share.reopen')->where('month', '\d{4}-\d{2}');
        Route::get('profit-share/{month}/pdf', [ProfitShareController::class, 'pdf'])->name('profit-share.pdf')->where('month', '\d{4}-\d{2}');
    });

    Route::middleware('module:providers')->group(function () {
        Route::resource('providers', ProviderController::class)->except(['show']);
        Route::resource('fx-rates', FxRateController::class)->only(['index', 'store', 'destroy']);
        Route::resource('bank-holidays', BankHolidayController::class)->only(['index', 'store', 'destroy']);
    });

    Route::middleware('module:bots')->group(function () {
        Route::get('bots', [BotController::class, 'index'])->name('bots.index');
        Route::post('bots/accounts', [BotController::class, 'store'])->name('bots.accounts.store');
        Route::put('bots/accounts/{account}', [BotController::class, 'update'])->name('bots.accounts.update');
        Route::delete('bots/accounts/{account}', [BotController::class, 'destroy'])->name('bots.accounts.destroy');
        Route::post('bots/accounts/{account}/run', [BotController::class, 'run'])->name('bots.accounts.run');
        Route::post('bots/runs/{run}/retry', [BotController::class, 'retry'])->name('bots.runs.retry');
        Route::get('bots/runs/{run}/screenshot', [BotController::class, 'screenshot'])->name('bots.runs.screenshot');
    });

    Route::middleware('module:documents')->group(function () {
        Route::resource('documents', DocumentController::class)->except(['create', 'edit']);
        Route::put('documents/{document}/status', DocumentStatusChangeController::class)->name('documents.status');
        Route::post('documents/{document}/comments', DocumentCommentController::class)->name('documents.comments.store');
        Route::post('documents/{document}/files', [DocumentFileController::class, 'store'])->name('documents.files.store');
        Route::get('documents/{document}/files/{file}', [DocumentFileController::class, 'show'])->name('documents.files.show')->scopeBindings();
        Route::delete('documents/{document}/files/{file}', [DocumentFileController::class, 'destroy'])->name('documents.files.destroy')->scopeBindings();
        Route::resource('document-templates', DocumentTemplateController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('document-templates/{document_template}/download', [DocumentTemplateController::class, 'download'])->name('document-templates.download');
        Route::resource('document-statuses', DocumentStatusController::class)->only(['index', 'store', 'update', 'destroy']);
    });

    Route::middleware('module:offers')->group(function () {
        Route::resource('offers', CommercialOfferController::class)->except(['show']);
        Route::post('offers/{offer}/status', [CommercialOfferController::class, 'status'])->name('offers.status');
        Route::post('offers/{offer}/accept', [CommercialOfferController::class, 'accept'])->name('offers.accept');
        Route::get('offers/{offer}/pdf', [CommercialOfferController::class, 'pdf'])->name('offers.pdf');
    });

    Route::middleware('module:team')->group(function () {
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team', [TeamController::class, 'store'])->name('team.store');
        Route::put('team/{member}', [TeamController::class, 'update'])->name('team.update');
        Route::post('team/{member}/password', [TeamController::class, 'resetPassword'])->name('team.password');
    });
});

require __DIR__.'/settings.php';
