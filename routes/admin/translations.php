<?php

declare(strict_types=1);

use App\Domain\Types\ContentTypeRegistry;
use App\Http\Admin\Contents\TranslationsController;
use Illuminate\Support\Facades\Route;

// The routes that translate an item, link and unlink its versions (I-3360).
// Each Operation asks its own abilities (D23).
Route::prefix('content/{type}/{content}')
    ->where(['type' => ContentTypeRegistry::segmentPattern()])
    ->group(function (): void {
        Route::post('translations', [TranslationsController::class, 'store'])->name('content.translations.store');
        Route::post('translations/link', [TranslationsController::class, 'link'])->name('content.translations.link');
        Route::post('translations/up-to-date', [TranslationsController::class, 'upToDate'])->name('content.translations.up-to-date');
        Route::delete('translations/{other}', [TranslationsController::class, 'unlink'])->name('content.translations.unlink');
        Route::put('language', [TranslationsController::class, 'language'])->name('content.language.update');
    });
