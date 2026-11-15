<?php

declare(strict_types=1);

use App\Http\Api\Manage\MeController;
use Illuminate\Support\Facades\Route;

// The management API's routes (docs/proposals/management-api.md), inside
// the group routes/api.php gives them: signed in with a key, never cached.

Route::get('me', MeController::class)->name('api.manage.me');
