<?php

declare(strict_types=1);

use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');

    // Oldest first, so the library, newest first, shows harbour, quay, fares, ferry, dunes.
    $this->dunes = mediaUpload($this->editor, mediaJpeg(1000, 700), 'dunes.jpg')->refresh();
    $this->travel(1)->minutes();
    $this->ferry = mediaUpload($this->editor, mediaJpeg(900, 600), 'ferry.jpg')->refresh();
    $this->travel(1)->minutes();
    $this->fares = mediaUpload($this->editor, mediaPdf(), 'fares.pdf')->refresh();
    $this->travel(1)->minutes();
    $this->quay = mediaUpload($this->editor, mediaJpeg(800, 600), 'quay.jpg')->refresh();
    $this->travel(1)->minutes();
    $this->harbour = mediaUpload($this->editor, mediaJpeg(1200, 800), 'harbour.jpg')->refresh();
    $this->travel(1)->minutes();

    // Warm the library before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get('/admin/media')->assertOk();
});

/** The tiles' order in the grid, as each tile's position of the focused one, or -1. */
function mlFocusedTile(): string
{
    return "[...document.querySelectorAll('[role=\"listbox\"] [role=\"option\"]')].indexOf(document.activeElement)";
}

/** Exactly one tile is in the tab order, and it has focus. */
function mlOneInTabOrder(): string
{
    return "document.querySelectorAll('[role=\"option\"][tabindex=\"0\"]').length === 1 && document.querySelector('[role=\"option\"][tabindex=\"0\"]') === document.activeElement";
}

it('draws tiles at three sizes, keeps the size after a reload, and is two across at M on a phone', function (): void {
    $size = fn (string $name): string => '[data-slot="segmented"][aria-label="Tile size"] [aria-label="'.$name.'"]';
    $minimum = "getComputedStyle(document.querySelector('[role=\"listbox\"]')).getPropertyValue('--media-cols')";

    $page = cmSignIn($this->editor)->resize(1280, 900);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"segmented\"][aria-label=\"Tile size\"]') !== null")
        ->click($size('Small tiles'));
    cmWaitUntil($page, "{$minimum}.includes('136px')")
        ->click($size('Medium tiles'));
    cmWaitUntil($page, "{$minimum}.includes('172px')")
        ->click($size('Large tiles'));
    cmWaitUntil($page, "{$minimum}.includes('228px')");

    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') !== null && {$minimum}.includes('228px')")
        ->assertAttribute($size('Large tiles'), 'data-state', 'on')
        ->click($size('Medium tiles'));
    cmWaitUntil($page, "{$minimum}.includes('172px')");

    $page->resize(375, 812);
    cmWaitUntil($page, "(() => { const tiles = document.querySelectorAll('[role=\"listbox\"] [role=\"option\"]'); return tiles.length >= 3 && tiles[0].offsetTop === tiles[1].offsetTop && tiles[2].offsetTop > tiles[1].offsetTop; })()");
});

it('shows each tile’s facts: Used 2×, Unused, No alt, and PDF over a document', function (): void {
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($this->harbour->id, (string) $this->editor->id, 'harbour', 'Boats at dawn', null, null, 50, 50, null));
    refsDraft($this->editor, refsFeatured($this->harbour));
    refsDraft($this->editor, refsFeatured($this->harbour));
    $tile = fn (Media $media): string => '[role="option"][data-media-id="'.$media->id.'"]';

    $page = cmSignIn($this->editor)->resize(1280, 900);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"] [role=\"option\"]') !== null")
        ->assertSeeIn($tile($this->harbour), 'Used 2×')
        ->assertDontSeeIn($tile($this->harbour), 'No alt')
        ->assertSeeIn($tile($this->quay), 'Unused')
        ->assertSeeIn($tile($this->quay), 'No alt')
        ->assertSeeIn($tile($this->fares), 'PDF')
        ->assertSeeIn($tile($this->fares), 'Unused')
        ->assertDontSeeIn($tile($this->fares), 'No alt');
});

it('moves between tiles with the arrows, Home and End, one tile in the tab order', function (): void {
    // Narrow enough that five tiles take two rows.
    $page = cmSignIn($this->editor)->resize(700, 900);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelectorAll('[role=\"listbox\"] [role=\"option\"]').length === 5")
        ->script("document.querySelector('[role=\"option\"][tabindex=\"0\"]').focus()");

    $columns = (int) $page->script("(() => { const tiles = [...document.querySelectorAll('[role=\"listbox\"] [role=\"option\"]')]; return tiles.filter((tile) => tile.offsetTop === tiles[0].offsetTop).length; })()");
    expect($columns)->toBeGreaterThan(1)->toBeLessThan(5);

    $press = function (string $key, int $expected) use ($page): void {
        $page->keys('[role="option"][tabindex="0"]', $key);
        cmWaitUntil($page, mlOneInTabOrder().' && '.mlFocusedTile()." === {$expected}");
    };

    $press('ArrowRight', 1);
    $press('ArrowLeft', 0);
    $press('ArrowDown', $columns);
    $press('ArrowUp', 0);
    $press('End', 4);
    $press('Home', 0);
    $press('ArrowLeft', 0);
});
