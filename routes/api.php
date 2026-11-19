<?php

/**
 * The API's routes, under /api, answered before the public site.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use App\Http\Api\Manage\Middleware\AnswerManageApi;
use App\Http\Api\Manage\Middleware\AuthenticateApiKey;
use App\Http\Api\Manage\Middleware\EnsureManageApiOn;
use App\Http\Api\Manage\Middleware\ThrottleManageAddress;
use App\Http\Api\Manage\Middleware\ThrottleManageKey;
use App\Http\Api\Middleware\AnswerAsApi;
use App\Http\Api\Middleware\EnsureApiOn;
use App\Http\Api\V1\NotFoundController;
use App\Http\Site\Middleware\MarkCacheable;
use Illuminate\Support\Facades\Route;

// The API (I-3324): loaded in the api group, before routes/public.php, so
// every address under /api is answered here and never by the public site.

// A part's routes go in routes/api/*.php, required here, so they are added
// before the catch-all. A route registered anywhere else names its middleware
// in one call, Route::middleware(['api', 'throttle:api', …])->prefix('api/v1'):
// a second middleware() call on the registrar replaces the first. The limit
// comes first, so an api_off answer is counted too.
Route::prefix('api/v1')->middleware(['throttle:api', EnsureApiOn::class])->group(function (): void {
    require __DIR__.'/api/catalogue.php';
    require __DIR__.'/api/items.php';
});

// The management API (docs/proposals/management-api.md): its own switch,
// signed in with a key, limited per address and per key, and never stored
// by the page cache, so the API group's ETag and cache mark are left out.
Route::prefix('api/manage/v1')
    ->middleware([AnswerManageApi::class, ThrottleManageAddress::class, EnsureManageApiOn::class, AuthenticateApiKey::class, ThrottleManageKey::class])
    ->withoutMiddleware([AnswerAsApi::class, MarkCacheable::class])
    ->group(base_path('routes/api/manage.php'));

// Anything else under /api, in any case: a problem, never the public site,
// whatever the switch says. The public site reads addresses in any case,
// so /API/… is the API's too.
Route::match(['GET', 'HEAD'], '{api}/{rest?}', NotFoundController::class)->where('api', '(?i:api)')->where('rest', '.*')->middleware('throttle:api')->name('api.not-found')->fallback();
