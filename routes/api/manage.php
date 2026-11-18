<?php

/**
 * The management API's routes, signed in with a key.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

use App\Http\Api\Manage\ItemsController;
use App\Http\Api\Manage\LookupController;
use App\Http\Api\Manage\MeController;
use App\Http\Api\Manage\MediaController;
use App\Http\Api\Manage\Middleware\RequireApiAbility;
use Illuminate\Support\Facades\Route;

// The management API's routes (docs/proposals/management-api.md), inside
// the group routes/api.php gives them: signed in with a key, never cached.
// Each route names the abilities a key needs; the person's own permission
// is the Operation's to decide.

Route::get('me', MeController::class)->name('api.manage.me');

Route::middleware(RequireApiAbility::class.':site.read')->group(function (): void {
    Route::get('taxonomies/{taxonomy}/terms', [LookupController::class, 'terms'])
        ->where('taxonomy', '[a-z][a-z0-9_-]*')
        ->name('api.manage.terms');
    Route::get('people', [LookupController::class, 'people'])->name('api.manage.people');
});

Route::middleware(RequireApiAbility::class.':media.write')->group(function (): void {
    Route::post('media', [MediaController::class, 'store'])->name('api.manage.media.store');
    Route::patch('media/{media}', [MediaController::class, 'update'])->where('media', '[0-9A-Za-z]{26}')->name('api.manage.media.update');
});

// content.publish too, to publish or schedule: ApiItems asks for it then.
Route::post('items', [ItemsController::class, 'store'])
    ->middleware(RequireApiAbility::class.':content.write')
    ->name('api.manage.items.store');
