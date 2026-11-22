<?php

use App\Http\Controllers\Admin\ModerationReportsController;
use App\Http\Controllers\ContentReportController;
use App\Support\ModerationReports;
use Illuminate\Support\Facades\Route;

Route::post('/reports/{type}/{target}', [ContentReportController::class, 'store'])
    ->whereIn('type', array_keys(ModerationReports::CONTENT_TYPES))->whereNumber('target')
    ->middleware(['auth', 'verified', 'throttle:abuse-reports'])->name('content.reports.store');

Route::prefix('admin/moderation/reports')->middleware(['auth', 'verified', 'can:manage-network'])
    ->name('moderation.reports.')->group(function () {
        Route::get('/', [ModerationReportsController::class, 'index'])->name('index');
        Route::post('/bulk/preview', [ModerationReportsController::class, 'preview'])->name('bulk.preview');
        Route::post('/bulk/resolve', [ModerationReportsController::class, 'bulkResolve'])->name('bulk.resolve');
        Route::get('/{type}/{report}', [ModerationReportsController::class, 'show'])
            ->whereIn('type', array_keys(ModerationReports::TYPES))->whereNumber('report')->name('show');
        Route::post('/{type}/{report}/decision', [ModerationReportsController::class, 'decide'])
            ->whereIn('type', array_keys(ModerationReports::TYPES))->whereNumber('report')->name('decision');
        Route::post('/conversation/{report}/evidence', [ModerationReportsController::class, 'evidence'])
            ->whereNumber('report')->middleware('throttle:moderation-evidence')->name('evidence');
    });
