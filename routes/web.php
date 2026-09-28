<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentCommentController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\DocumentFileController;
use App\Http\Controllers\Admin\DocumentStatusChangeController;
use App\Http\Controllers\Admin\DocumentStatusController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::redirect('dashboard', '/admin');

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::resource('documents', DocumentController::class)->except(['create', 'edit']);
    Route::put('documents/{document}/status', DocumentStatusChangeController::class)->name('documents.status');
    Route::post('documents/{document}/comments', DocumentCommentController::class)->name('documents.comments.store');
    Route::post('documents/{document}/files', [DocumentFileController::class, 'store'])->name('documents.files.store');
    Route::get('documents/{document}/files/{file}', [DocumentFileController::class, 'show'])->name('documents.files.show')->scopeBindings();
    Route::delete('documents/{document}/files/{file}', [DocumentFileController::class, 'destroy'])->name('documents.files.destroy')->scopeBindings();

    Route::resource('document-statuses', DocumentStatusController::class)->only(['index', 'store', 'update', 'destroy']);
});

require __DIR__.'/settings.php';
