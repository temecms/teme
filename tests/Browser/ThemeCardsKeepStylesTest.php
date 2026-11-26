<?php

/**
 * The default theme's broad rules leave core's cards' own styles alone,
 * now that the cards sit in the teme.core layer.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('keeps a button card white on its accent and a callout paragraph spaced as the card styles it', function (): void {
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $editor = User::factory()->create()->assignRole('editor');
    pageArticleFromDocument($editor, 'Card looks', [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before.']]],
        ['type' => 'button', 'attrs' => ['id' => '01JBTTN000000000000000001A', 'label' => 'Go', 'url' => 'https://example.com/', 'style' => 'primary']],
    ]);

    visit('/card-looks/')
        ->assertScript("getComputedStyle(document.querySelector('.card-button a, a.card-button')).color", 'rgb(255, 255, 255)')
        ->assertScript("parseFloat(getComputedStyle(document.querySelector('.prose .card-button')).marginBlockStart) > 0", true);
});
