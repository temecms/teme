<?php

/**
 * The default theme's broad element rules sit below core's card styles, so
 * each card keeps the look its own stylesheet gives it.
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
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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
    $first = pageImage($editor, 1600, 900);
    $second = pageImage($editor, 900, 1200);
    pageArticle($editor, 'Older post');

    $gallery = regionCardNode('gallery');
    $gallery['attrs']['mediaIds'] = [$first->id, $second->id];

    pageArticleFromDocument($editor, 'Card looks', [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Before.']]],
        ['type' => 'button', 'attrs' => ['id' => '01JBTTN000000000000000001A', 'label' => 'Go', 'url' => 'https://example.com/', 'style' => 'primary']],
        regionCardNode('callout'),
        $gallery,
        pageImageNode($first, (string) Str::ulid()),
        icFixture()['content'][0],
    ]);

    $featured = regionCardNode('featured-posts');
    $featured['attrs'] = ['id' => $featured['attrs']['id'], 'heading' => 'More to read', 'source' => 'latest', 'type' => 'post', 'count' => 3, 'images' => true];
    $region = regionCreate($editor, 'default');
    regionSave($region, $editor, [$featured]);
    listsPublish($region, $editor);
});

it('keeps a button card white on its accent and spaced as the card styles it', function (): void {
    visit('/card-looks/')
        ->assertScript("getComputedStyle(document.querySelector('a.card-button')).color", 'rgb(255, 255, 255)')
        ->assertScript("getComputedStyle(document.querySelector('.prose .card-button')).marginBlockStart", '16px');
});

it('keeps a callout spaced as its card styles it, with the paragraph inside it at no margin', function (): void {
    visit('/card-looks/')
        ->assertScript("getComputedStyle(document.querySelector('.prose .card-callout')).marginBlockStart", '24px')
        ->assertScript("getComputedStyle(document.querySelector('.card-callout p')).marginBlockStart", '0px');
});

it('keeps a gallery picture filling its cell and the image card its own spacing', function (): void {
    visit('/card-looks/')
        ->assertScript("document.querySelector('.card-gallery img').computedStyleMap().get('block-size').toString()", '100%')
        ->assertScript("getComputedStyle(document.querySelector('.card-gallery')).marginBlockStart", '24px');
});

it('keeps imported content\'s own definition indent, and the featured posts heading its margin', function (): void {
    visit('/card-looks/')
        ->assertScript("getComputedStyle(document.querySelector('.card-imported dd')).marginInlineStart", '25px')
        ->assertScript("getComputedStyle(document.querySelector('.card-featured-posts-heading')).marginBlockEnd", '8px');
});
