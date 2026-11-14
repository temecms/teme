<?php

declare(strict_types=1);

use App\Http\Admin\Settings\LanguagesController;
use Illuminate\Support\Facades\Route;

// Settings › Languages (I-3360): its own screen, before settings/{group}, and
// the Operations that add, change and remove a language.
Route::middleware('can:settings.general.manage')->group(function (): void {
    Route::get('settings/languages', [LanguagesController::class, 'show'])->name('settings.languages');
    Route::post('settings/languages', [LanguagesController::class, 'store'])->name('settings.languages.store');
    Route::put('settings/languages/translated', [LanguagesController::class, 'translated'])->name('settings.languages.translated');
    Route::put('settings/languages/{tag}', [LanguagesController::class, 'update'])->where('tag', '[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*')->name('settings.languages.update');
    Route::delete('settings/languages/{tag}', [LanguagesController::class, 'destroy'])->where('tag', '[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*')->name('settings.languages.destroy');
});
