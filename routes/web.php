<?php

/**
 * The web routes outside the admin and the public site, such as preview links,
 * and the files that add the rest.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use App\Http\Site\PreviewController;
use Illuminate\Support\Facades\Route;

// One revision of one article for someone with no account, through a link
// that expires and can be revoked. Outside every auth middleware, and
// rate-limited so a token cannot be guessed at speed.
Route::get('preview/{token}', [PreviewController::class, 'show'])
    ->middleware('throttle:preview')
    ->name('preview.show');

require __DIR__.'/admin.php';
