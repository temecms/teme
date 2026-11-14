<?php

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
