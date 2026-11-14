<?php

declare(strict_types=1);

use App\Http\Api\V1\MediaController;
use App\Http\Api\V1\SiteController;
use App\Http\Api\V1\TaxonomiesController;
use App\Http\Api\V1\TermsController;
use App\Http\Api\V1\TypesController;
use Illuminate\Support\Facades\Route;

// The catalogue: the site, types, taxonomies, terms and media (part 3.1b).
// An ID is a string of its form, never a bound model (D4): items' and terms'
// are lower-case ULIDs, media's upper-case ones.
Route::get('/', SiteController::class)->name('api.v1.site');
Route::get('/types', TypesController::class)->name('api.v1.types');
Route::get('/taxonomies', TaxonomiesController::class)->name('api.v1.taxonomies');
Route::get('/terms', [TermsController::class, 'index'])->name('api.v1.terms.index');
Route::get('/terms/{id}', [TermsController::class, 'show'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->name('api.v1.terms.show');
Route::get('/media/{id}', MediaController::class)->where('id', '[0-9A-HJKMNP-TV-Z]{26}')->name('api.v1.media.show');
