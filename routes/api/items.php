<?php

/**
 * The API's items: the list, one item, and an item by its address.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use App\Http\Api\V1\ItemsController;
use Illuminate\Support\Facades\Route;

// Items: the list, one item, and an item by its address (part 3.1a).
Route::get('items/by-path', [ItemsController::class, 'byPath'])->name('api.v1.items.by-path');
Route::get('items', [ItemsController::class, 'index'])->name('api.v1.items.index');
Route::get('items/{id}', [ItemsController::class, 'show'])->where('id', '[0-9a-hjkmnp-tv-z]{26}')->name('api.v1.items.show');
