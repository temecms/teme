<?php

declare(strict_types=1);

use App\Domain\Media\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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

    // Oldest first, so the picker, newest first, shows harbour, ferry, dunes.
    $this->dunes = mediaUpload($this->editor, mediaJpeg(1000, 700), 'dunes.jpg')->refresh();
    $this->travel(1)->minutes();
    $this->ferry = mediaUpload($this->editor, mediaJpeg(900, 600), 'ferry.jpg')->refresh();
    $this->travel(1)->minutes();
    $this->harbour = mediaUpload($this->editor, mediaJpeg(1200, 800), 'harbour.jpg')->refresh();
    $this->travel(1)->minutes();

    // Warm the catalogue before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get('/admin/_catalogue')->assertOk();

    // Pest's in-process server takes no files, so the page's upload request is answered in the
    // page, as MediaScreenTest does: progress, then a 201 naming a file the test put in the library.
    $this->fakeUploads = fn (Media $answer): string => str_replace('__BODY__', json_encode(['id' => $answer->id], JSON_THROW_ON_ERROR), <<<'JS'
        (() => {
            const Real = window.XMLHttpRequest;

            class StandIn extends Real {
                open(method, url, ...rest) {
                    this.faked = method.toUpperCase() === 'POST' && new URL(url, window.location.href).pathname === '/admin/media';

                    if (!this.faked) {
                        super.open(method, url, ...rest);
                    }
                }

                setRequestHeader(name, value) {
                    if (!this.faked) {
                        super.setRequestHeader(name, value);
                    }
                }

                send(body) {
                    if (!this.faked) {
                        super.send(body);

                        return;
                    }

                    window.setTimeout(() => this.upload.dispatchEvent(new ProgressEvent('progress', { lengthComputable: true, loaded: 1, total: 2 })), 300);
                    window.setTimeout(() => {
                        Object.defineProperty(this, 'status', { value: 201 });
                        Object.defineProperty(this, 'responseText', { value: JSON.stringify(__BODY__) });
                        this.dispatchEvent(new ProgressEvent('load'));
                    }, 900);
                }
            }

            window.XMLHttpRequest = StandIn;

            return true;
        })()
        JS);
});

/** The catalogue's picker section, signed in, at the given width. */
function mpCatalogue(User $user, int $width = 1280, int $height = 900): mixed
{
    $page = cmNavigate(cmSignIn($user)->resize($width, $height), '/admin/_catalogue#media-picker');

    return cmWaitUntil($page, "document.querySelector('[data-test=\"catalogue-picker-featured\"]') !== null");
}

/** Opens the picker from the catalogue's button for a caller, and waits for its tiles. */
function mpOpen(mixed $page, string $caller): mixed
{
    $page->click('[data-test="catalogue-picker-'.$caller.'"]');
    shellSettled($page, '[data-slot="dialog-content"]');

    return cmWaitUntil($page, "document.querySelectorAll('[role=\"dialog\"] [role=\"option\"]').length === 3");
}

/** The picker's tile for a file. */
function mpTile(Media $media): string
{
    return '[role="dialog"] [role="option"][data-media-id="'.$media->id.'"]';
}

/** The IDs the catalogue last heard from the picker, in order. */
function mpChosen(): string
{
    return "document.querySelector('[data-test=\"catalogue-picker-chosen\"]').textContent";
}

/** The tray's files, in order, as their IDs joined by commas. */
function mpTray(): string
{
    return "[...document.querySelectorAll('[data-test=\"media-modal-tray\"] li')].map((item) => item.dataset.mediaId).join(',')";
}

it('opens with each caller’s title and main button', function (): void {
    $page = mpCatalogue($this->editor);
    $title = "document.querySelector('[role=\"dialog\"] h2')?.textContent";
    $use = "document.querySelector('[data-test=\"media-modal-use\"]')?.textContent";

    foreach ([
        'insert' => ['Insert an image', 'Insert image'],
        'replace' => ['Replace the image', 'Replace'],
        'featured' => ['Choose the featured image', 'Set featured image'],
        'choose' => ['Choose an image', 'Use as poster'],
    ] as $caller => [$heading, $button]) {
        mpOpen($page, $caller);
        cmWaitUntil($page, "{$title} === ".json_encode($heading).' && '."{$use} === ".json_encode($button))
            ->click('[data-test="media-modal-cancel"]');
        cmWaitUntil($page, "document.querySelector('[data-slot=\"dialog-content\"]') === null");
    }

    mpOpen($page, 'gallery')
        ->click(mpTile($this->harbour))
        ->click(mpTile($this->ferry))
        ->click(mpTile($this->dunes));
    cmWaitUntil($page, "{$title} === 'Add images to the gallery' && {$use} === 'Add 3 images'");
});

it('chooses a file by keyboard alone and inserts it, then returns focus to the opener', function (): void {
    $page = mpCatalogue($this->editor);
    $page->script("document.querySelector('[data-test=\"catalogue-picker-replace\"]').focus()");
    $page->keys(':focus', 'Tab');
    cmWaitUntil($page, "document.activeElement?.dataset.test === 'catalogue-picker-featured'");
    $page->keys(':focus', 'Enter');
    shellSettled($page, '[data-slot="dialog-content"]');
    cmWaitUntil($page, "document.querySelectorAll('[role=\"dialog\"] [role=\"option\"]').length === 3");

    // Tab, as a person would, until the grid's one tab stop has focus.
    for ($presses = 0; $presses < 30 && $page->script("document.activeElement?.getAttribute('role')") !== 'option'; $presses++) {
        $page->keys(':focus', 'Tab');
    }

    cmWaitUntil($page, "document.activeElement?.dataset.mediaId === '{$this->harbour->id}'");
    $page->keys(':focus', 'ArrowRight');
    cmWaitUntil($page, "document.activeElement?.dataset.mediaId === '{$this->ferry->id}'");
    $page->keys(':focus', ' ');
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-status\"]').textContent === '1 file selected'");
    $page->keys(':focus', 'Enter');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"dialog-content\"]') === null && ".mpChosen()." === '{$this->ferry->id}'");
    cmWaitUntil($page, "document.activeElement?.dataset.test === 'catalogue-picker-featured'");
});

it('keeps a gallery in the order chosen, and the tray removes one', function (): void {
    $page = mpOpen(mpCatalogue($this->editor), 'gallery')
        ->click(mpTile($this->dunes))
        ->click(mpTile($this->harbour))
        ->click(mpTile($this->ferry));
    cmWaitUntil($page, mpTray()." === '{$this->dunes->id},{$this->harbour->id},{$this->ferry->id}'")
        ->click('[data-test="media-modal-tray"] li[data-media-id="'.$this->harbour->id.'"] button');
    cmWaitUntil($page, mpTray()." === '{$this->dunes->id},{$this->ferry->id}'")
        ->click('[data-test="media-modal-use"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"dialog-content\"]') === null && ".mpChosen()." === '{$this->dunes->id},{$this->ferry->id}'");
});

it('inserts at once on a double-click in a single choice, and Cancel and Esc call nothing', function (): void {
    $doubleClick = fn (Media $media): string => "(() => { const tile = document.querySelector('".mpTile($media)."'); tile.click(); tile.click(); tile.dispatchEvent(new MouseEvent('dblclick', { bubbles: true, detail: 2 })); return true; })()";

    $page = mpOpen(mpCatalogue($this->editor), 'featured');
    $page->script($doubleClick($this->ferry));
    cmWaitUntil($page, "document.querySelector('[data-slot=\"dialog-content\"]') === null && ".mpChosen()." === '{$this->ferry->id}'");

    mpOpen($page, 'featured')->click(mpTile($this->harbour));
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-status\"]').textContent === '1 file selected'")
        ->click('[data-test="media-modal-cancel"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"dialog-content\"]') === null");

    mpOpen($page, 'featured')->click(mpTile($this->harbour));
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-status\"]').textContent === '1 file selected'");
    $page->keys(':focus', 'Escape');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"dialog-content\"]') === null && ".mpChosen()." === '{$this->ferry->id}'");
});

it('uploads in the Upload tab and chooses the finished file', function (): void {
    $page = mpOpen(mpCatalogue($this->editor), 'featured');

    // Put in the library after the grid is drawn, as the upload would.
    $quay = mediaUpload($this->editor, mediaJpeg(800, 600), 'quay.jpg')->refresh();
    $page->script(($this->fakeUploads)($quay));
    $page->click('[role="dialog"] [role="tab"]:text-is("Upload")');
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-drop-zone\"]') !== null")
        ->attach('[data-test="media-modal-upload-input"]', mediaJpeg(640, 480));
    cmWaitUntil($page, "document.querySelector('[role=\"dialog\"] [role=\"progressbar\"]') !== null");
    cmWaitUntil($page, mpTray()." === '{$quay->id}' && document.querySelector('[data-test=\"media-modal-status\"]').textContent === 'Uploaded 1 file. Add its alt text.'");
});

it('waits for a pending upload before setting the featured image', function (): void {
    // No queue worker: the upload stays pending until the test marks it ready.
    Queue::fake();
    $page = mpOpen(mpCatalogue($this->editor), 'featured');

    $quay = mediaUpload($this->editor, mediaJpeg(800, 600), 'quay.jpg')->refresh();
    expect($quay->processing)->toBe('pending');

    $page->script(($this->fakeUploads)($quay));
    $page->click('[role="dialog"] [role="tab"]:text-is("Upload")');
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-drop-zone\"]') !== null")
        ->attach('[data-test="media-modal-upload-input"]', mediaJpeg(640, 480));
    cmWaitUntil($page, mpTray()." === '{$quay->id}' && document.querySelector('[data-test=\"media-modal-status\"]').textContent === 'Waiting for the image to be prepared…'");
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-use\"]').disabled === true");

    DB::table('media')->where('id', $quay->id)->update(['processing' => 'ready']);

    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-use\"]').disabled === false && document.querySelector('[data-test=\"media-modal-status\"]').textContent !== 'Waiting for the image to be prepared…'")
        ->click('[data-test="media-modal-use"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"dialog-content\"]') === null && ".mpChosen()." === '{$quay->id}'");
});

it('edits alt text in the side, saved when left, and the library shows it', function (): void {
    $alt = '[data-test="media-modal-details"] #attachment-'.$this->dunes->id.'-alt';

    $page = mpOpen(mpCatalogue($this->editor), 'featured')->click(mpTile($this->dunes));
    cmWaitUntil($page, "document.querySelector('{$alt}') !== null")
        ->type($alt, 'Grass on the dunes at dusk')
        ->keys($alt, 'Tab');
    cmWaitUntil($page, "[...document.querySelectorAll('[data-test=\"flash-toast\"]')].some((toast) => toast.innerText.includes('Saved.'))")
        ->click('[data-test="media-modal-cancel"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"dialog-content\"]') === null");

    cmNavigate($page, '/admin/media');
    cmWaitUntil($page, "document.querySelector('[role=\"listbox\"] [role=\"option\"][data-media-id=\"{$this->dunes->id}\"]') !== null")
        ->assertDontSeeIn('[role="option"][data-media-id="'.$this->dunes->id.'"]', 'No alt')
        ->assertSeeIn('[role="option"][data-media-id="'.$this->ferry->id.'"]', 'No alt');

    expect($this->dunes->refresh()->alt)->toBe('Grass on the dunes at dusk');
});

it('replaces its options when opened again from another caller', function (): void {
    $page = mpOpen(mpCatalogue($this->editor), 'featured')->click(mpTile($this->harbour));
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-status\"]').textContent === '1 file selected'");

    // A second caller while the first is open, as a script would.
    $page->script("document.querySelector('[data-test=\"catalogue-picker-gallery\"]').click()");
    cmWaitUntil($page, "document.querySelectorAll('[data-slot=\"dialog-content\"]').length === 1 && document.querySelector('[role=\"dialog\"] h2')?.textContent === 'Add images to the gallery'");
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-tray\"]') === null && document.querySelector('[data-test=\"media-modal-status\"]').textContent.startsWith('Select one or more files')")
        ->click('[data-test="media-modal-cancel"]');

    // Focus goes back to what opened the first picker, not to whatever the replaced one held.
    cmWaitUntil($page, "document.querySelector('[data-slot=\"dialog-content\"]') === null && document.activeElement?.dataset.test === 'catalogue-picker-featured'");
});

it('calls onClose once on Cancel, on Esc and on the close button, and not on insert', function (): void {
    $closed = "document.querySelector('[data-test=\"catalogue-picker-closed\"]').textContent";
    $gone = "document.querySelector('[data-slot=\"dialog-content\"]') === null";

    $page = mpOpen(mpCatalogue($this->editor), 'featured')->click('[data-test="media-modal-cancel"]');
    cmWaitUntil($page, "{$gone} && {$closed} === '1'");

    mpOpen($page, 'featured')->click(mpTile($this->harbour));
    $page->keys(':focus', 'Escape');
    cmWaitUntil($page, "{$gone} && {$closed} === '2'");

    mpOpen($page, 'featured')
        ->script("[...document.querySelectorAll('[role=\"dialog\"] button')].find((button) => button.textContent.trim() === 'Close').click()");
    cmWaitUntil($page, "{$gone} && {$closed} === '3'");

    mpOpen($page, 'featured')->click(mpTile($this->ferry))->click('[data-test="media-modal-use"]');
    cmWaitUntil($page, "{$gone} && ".mpChosen()." === '{$this->ferry->id}'")
        ->assertScript($closed, '3');
});

it('passes axe on both tabs, every target 24 pixels or more, and fills the screen at 375 pixels, in both schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = mpOpen(mpCatalogue($this->editor), 'gallery')->click(mpTile($this->harbour));
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-tray\"] li') !== null && document.querySelector('[data-test=\"media-modal-details\"] input') !== null");
    $library = cmAxeViolations($page);
    $libraryTargets = cmSmallTargets($page);

    $page->click('[role="dialog"] [role="tab"]:text-is("Upload")');
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-drop-zone\"]') !== null");
    $upload = cmAxeViolations($page);
    $uploadTargets = cmSmallTargets($page);

    $phone = mpOpen(mpCatalogue($this->editor, 375, 812), 'featured');
    // Measured against a fixed box over the whole screen: the scrollbar's room stays kept (I-3130).
    $box = $phone->script("(() => { const screen = document.createElement('div'); screen.style.cssText = 'position: fixed; inset: 0'; document.body.append(screen); const whole = screen.getBoundingClientRect(); screen.remove(); const box = document.querySelector('[data-slot=\"dialog-content\"]').getBoundingClientRect(); return [Math.round(box.left), Math.round(box.top), Math.round(box.width) === Math.round(whole.width), Math.round(box.height) === Math.round(whole.height)]; })()");

    expect($library)->toBe([])
        ->and($libraryTargets['small'])->toBe([])
        ->and($libraryTargets['checked'])->toBeGreaterThan(0)
        ->and($upload)->toBe([])
        ->and($uploadTargets['small'])->toBe([])
        ->and($uploadTargets['checked'])->toBeGreaterThan(0)
        ->and($box)->toBe([0, 0, true, true]);
})->with(['light', 'dark']);
