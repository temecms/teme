<?php

declare(strict_types=1);

use App\Http\Api\Middleware\EnsureApiOn;
use App\Http\Api\V1\NotFoundController;
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

// Anything else under /api, in any case: a problem, never the public site,
// whatever the switch says. The public site reads addresses in any case,
// so /API/… is the API's too.
Route::match(['GET', 'HEAD'], '{api}/{rest?}', NotFoundController::class)->where('api', '(?i:api)')->where('rest', '.*')->middleware('throttle:api')->name('api.not-found')->fallback();
