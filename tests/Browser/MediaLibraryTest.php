<?php

declare(strict_types=1);

use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\TrashMedia;
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

/** The detail panel holds this text. */
function mlPanelHolds(string $text): string
{
    return "document.querySelector('[data-test=\"media-detail\"]')?.innerText.includes(".json_encode($text, JSON_THROW_ON_ERROR).') === true';
}

/** How many “Saved.” toasts have been shown, counting those still on screen. */
function mlSavedToasts(): string
{
    return "[...document.querySelectorAll('[data-test=\"flash-toast\"]')].filter((toast) => toast.innerText.includes('Saved.')).length";
}

it('opens a file’s details beside the library with Enter, says 2 of 3, steps with Next and ←, and Esc returns focus to the tile', function (): void {
    foreach ([$this->ferry, $this->dunes] as $gone) {
        app(TrashMedia::class)->execute(new MediaActionInput($gone->id, (string) $this->editor->id));
    }

    $page = cmSignIn($this->editor)->resize(1280, 900);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelectorAll('[role=\"listbox\"] [role=\"option\"]').length === 3")
        ->assertSeeIn('[aria-label="File details"]', 'Select a file to see its details.')
        ->script("document.querySelector('[role=\"option\"][tabindex=\"0\"]').focus()");
    $page->keys('[role="option"][tabindex="0"]', 'ArrowRight');
    $page->keys('[role="option"][tabindex="0"]', 'Enter');
    cmWaitUntil($page, mlPanelHolds('2 of 3').' && '.mlPanelHolds('quay.jpg'))
        ->assertNotPresent('[data-slot="sheet-content"]')
        ->click('[data-test="media-detail-next"]');
    cmWaitUntil($page, mlPanelHolds('3 of 3').' && '.mlPanelHolds('fares.pdf'));
    $page->keys('[data-test="media-detail-previous"]', 'ArrowLeft');
    cmWaitUntil($page, mlPanelHolds('2 of 3').' && '.mlPanelHolds('quay.jpg'));
    $page->keys('[data-test="media-detail-previous"]', 'Escape');
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-detail\"]') === null && document.activeElement?.dataset.mediaId === ".json_encode($this->quay->id));
});

it('shows the details in a sheet below 1024 pixels, and the sheet becomes the panel when the window widens', function (): void {
    $page = cmSignIn($this->editor)->resize(900, 900);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"] [role=\"option\"]') !== null")
        ->click('[role="option"]:has-text("harbour")');
    shellSettled($page, '[data-slot="sheet-content"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"sheet-content\"]')?.innerText.includes('harbour.jpg') === true");

    $page->resize(1280, 900);
    cmWaitUntil($page, "document.querySelector('[data-slot=\"sheet-content\"]') === null && ".mlPanelHolds('harbour.jpg'));
});

it('saves a field when it is left, says so, and a second field’s save keeps the first', function (): void {
    $field = fn (string $name): string => '#attachment-'.$this->harbour->id.'-'.$name;

    $page = cmSignIn($this->editor)->resize(1280, 900);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"] [role=\"option\"]') !== null")
        ->click('[role="option"]:has-text("harbour")');
    cmWaitUntil($page, "document.querySelector('{$field('caption')}') !== null")
        ->type($field('caption'), 'Boats at dawn')
        ->keys($field('caption'), 'Tab');
    cmWaitUntil($page, mlSavedToasts().' >= 1')
        ->type($field('credit'), 'Photo: Kemi Adeyemi')
        ->click('[data-test="media-detail-next"]');
    cmWaitUntil($page, mlPanelHolds('2 of 5').' && '.mlSavedToasts().' >= 2');

    expect(Media::find($this->harbour->id)->only(['caption', 'credit']))->toBe(['caption' => 'Boats at dawn', 'credit' => 'Photo: Kemi Adeyemi']);
});

it('keeps what was typed under the field when the server refuses it', function (): void {
    $title = '#attachment-'.$this->harbour->id.'-title';
    $long = str_repeat('Harbour ', 40);

    $page = cmSignIn($this->editor)->resize(1280, 900);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"] [role=\"option\"]') !== null")
        ->click('[role="option"]:has-text("harbour")');
    cmWaitUntil($page, "document.querySelector('{$title}') !== null")
        ->type($title, $long)
        ->keys($title, 'Tab');
    cmWaitUntil($page, "document.querySelector('{$title}-error')?.innerText.includes('255') === true")
        ->assertValue($title, $long);

    expect(Media::find($this->harbour->id)->title)->toBe('harbour');
});

it('moves the focal point by keyboard, 1 and 10 at a time, and saves it when the picture is left', function (): void {
    $stage = '[data-test="media-focal-point"]';

    $page = cmSignIn($this->editor)->resize(1280, 900);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"] [role=\"option\"]') !== null")
        ->click('[role="option"]:has-text("harbour")');
    cmWaitUntil($page, "document.querySelector('{$stage}') !== null")
        ->script("document.querySelector('{$stage}').focus()");
    $page->keys($stage, ['ArrowRight', 'ArrowRight', 'Shift+ArrowDown']);
    cmWaitUntil($page, "document.querySelector('{$stage}').innerText.includes('52% across, 60% down') || document.querySelector('{$stage}').textContent.includes('52% across, 60% down')");
    $page->keys($stage, 'Tab');
    cmWaitUntil($page, "[...document.querySelectorAll('[data-test=\"flash-toast\"]')].some((toast) => toast.innerText.includes('Its sizes are being made again around the new focal point.'))");

    expect(Media::find($this->harbour->id)->only(['focal_x', 'focal_y']))->toBe(['focal_x' => 52, 'focal_y' => 60]);
});

it('names the people whose picture it is before moving it to the trash, and before deleting it', function (): void {
    User::factory()->create(['picture_media_id' => $this->harbour->id]);
    $dialog = '[data-slot="dialog-content"]';

    $page = cmSignIn($this->editor)->resize(1280, 900);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"] [role=\"option\"]') !== null")
        ->click('[role="option"]:has-text("harbour")');
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-picture-of\"]')?.innerText.includes('1 person') === true && document.querySelector('[data-test=\"media-trash\"]')?.disabled === false")
        ->click('[data-test="media-trash"]');
    shellSettled($page, $dialog)
        ->assertSeeIn($dialog, '1 person uses it as their picture')
        ->click($dialog.' button:text-is("Move to trash")')
        ->click('button:text-is("Undo")')
        ->assertSee('Restored. Every item that uses it shows it again.');

    expect(Media::query()->whereKey($this->harbour->id)->exists())->toBeTrue();

    app(TrashMedia::class)->execute(new MediaActionInput($this->harbour->id, (string) $this->editor->id));
    cmNavigate($page, '/admin/media?tab=trash');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"] [role=\"option\"]') !== null")
        ->click('[role="option"]:has-text("harbour")');
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-delete\"]')?.disabled === false")
        ->click('[data-test="media-delete"]');
    shellSettled($page, $dialog)
        ->assertSeeIn($dialog, '1 person loses their picture.');
});

it('lists files in the list view, and Used in opens the details', function (): void {
    refsDraft($this->editor, refsFeatured($this->harbour));
    refsDraft($this->editor, refsFeatured($this->harbour));

    $page = cmSignIn($this->editor)->resize(1280, 900);
    cmNavigate($page, '/admin/media?view=list');
    cmWaitUntil($page, "document.querySelector('table') !== null")
        ->click('[aria-label="Used in 2 places: show them"]');
    cmWaitUntil($page, mlPanelHolds('harbour.jpg')." && document.querySelectorAll('[data-test=\"media-used-in\"] li').length === 2");
});

it('passes axe with the panel open and empty, the list, and the sheet, every target 24 pixels or more, at 1280 and 375 pixels in both schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);
    $small = [];

    $page = cmSignIn($this->editor)->resize(1280, 900);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"] [role=\"option\"]') !== null")
        ->assertSeeIn('[aria-label="File details"]', 'Select a file to see its details.')
        ->assertNoAccessibilityIssues(3);
    $small['empty'] = cmSmallTargets($page);

    $page->click('[role="option"]:has-text("harbour")');
    cmWaitUntil($page, mlPanelHolds('harbour.jpg')." && document.querySelector('[data-test=\"media-trash\"]')?.disabled === false")
        ->assertNoAccessibilityIssues(3);
    $small['panel'] = cmSmallTargets($page);

    cmNavigate($page, '/admin/media?view=list');
    cmWaitUntil($page, "document.querySelector('table') !== null")
        ->assertNoAccessibilityIssues(3);
    $small['list'] = cmSmallTargets($page);

    $page->resize(375, 812);
    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"] [role=\"option\"]') !== null")
        ->assertNoAccessibilityIssues(3);
    $small['phone'] = cmSmallTargets($page);

    $page->click('[role="option"]:has-text("harbour")');
    shellSettled($page, '[data-slot="sheet-content"]');
    shellSettled($page, '[data-slot="sheet-overlay"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"sheet-content\"]')?.innerText.includes('harbour.jpg') === true && document.querySelector('[data-test=\"media-trash\"]')?.disabled === false")
        ->assertNoAccessibilityIssues(3);
    $small['sheet'] = cmSmallTargets($page);

    foreach ($small as $state => $targets) {
        expect($targets['small'])->toBe([], "Small targets in {$state}")
            ->and($targets['checked'])->toBeGreaterThan(0);
    }
})->with(['light', 'dark']);
