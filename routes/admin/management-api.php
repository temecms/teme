<?php

declare(strict_types=1);

use App\Http\Admin\Settings\ManagementApiController;
use Illuminate\Support\Facades\Route;

// Settings › Management API (docs/proposals/management-api.md), at its
// group's own address, before settings/{group}, which would otherwise catch it.
Route::middleware('can:api.keys.manage')->group(function (): void {
    Route::get('settings/management_api', [ManagementApiController::class, 'show'])->name('settings.management-api');
    Route::put('settings/management_api', [ManagementApiController::class, 'update'])->name('settings.management-api.update');
    Route::post('settings/management_api/keys', [ManagementApiController::class, 'store'])->name('settings.management-api.keys.store');
    Route::delete('settings/management_api/keys/{key}', [ManagementApiController::class, 'destroy'])
        ->where('key', '[0-9A-Za-z]{26}')
        ->name('settings.management-api.keys.destroy');
});
