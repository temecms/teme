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
    $this->harbour = mediaUpload($this->editor, mediaJpeg(1200, 800), 'harbour.jpg')->refresh();
    $this->travel(1)->minutes();
    $this->fares = mediaUpload($this->editor, mediaPdf(), 'fares.pdf')->refresh();
    $this->travel(1)->minutes();
    $this->field = fn (Media $media, string $name): string => '#attachment-'.$media->id.'-'.$name;

    // Warm the library before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get('/admin/media')->assertOk();

    // Pest's in-process server takes no files, so the page's upload request is answered in the
    // page: progress, then a 201 naming a file the test put in the library, or a 409 naming it
    // as the duplicate. Every other request, Inertia's included, goes to the server as usual.
    // window.__uploads lists the names sent.
    $this->fakeUploads = fn (Media $answer, int $status = 201): string => str_replace(['__STATUS__', '__BODY__'], [(string) $status, json_encode($status === 409 ? ['duplicate' => ['id' => $answer->id, 'title' => $answer->title]] : ['id' => $answer->id], JSON_THROW_ON_ERROR)], <<<'JS'
        (() => {
            const Real = window.XMLHttpRequest;
            window.__uploads = [];

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

                    window.__uploads.push(body.get('file').name);
                    window.setTimeout(() => this.upload.dispatchEvent(new ProgressEvent('progress', { lengthComputable: true, loaded: 1, total: 2 })), 300);
                    window.setTimeout(() => {
                        Object.defineProperty(this, 'status', { value: __STATUS__ });
                        Object.defineProperty(this, 'responseText', { value: JSON.stringify(__BODY__) });
                        this.dispatchEvent(new ProgressEvent('load'));
                    }, 900);
                }
            }

            window.XMLHttpRequest = StandIn;

            return true;
        })()
        JS);

    // A drag carrying one PNG, as the operating system sends it, dispatched on the page.
    $this->drag = fn (string $type): string => str_replace('__TYPE__', $type, <<<'JS'
        (() => {
            const transfer = new DataTransfer();
            transfer.items.add(new File(['quay'], 'quay.png', { type: 'image/png' }));
            document.body.dispatchEvent(new DragEvent('__TYPE__', { dataTransfer: transfer, bubbles: true, cancelable: true }));

            return true;
        })()
        JS);

    // axe on one part of the page, target sizes included: the editor around the modal is held to axe elsewhere.
    $this->axeIn = fn (mixed $page, string $selector): array => cmScriptArray($page, str_replace('__SELECTOR__', json_encode($selector, JSON_THROW_ON_ERROR), <<<'JS'
        async () => {
            const run = await window.axe.run(document.querySelector(__SELECTOR__), { rules: { 'target-size': { enabled: true } } });

            return run.violations.map((violation) => violation.id);
        }
        JS));
});

it('uploads from the page header, one bar a file, and shows the file in the library once it is in (I-440)', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/media');
    // Put in the library after the page drew its list, so only the reload can show it.
    $ferry = mediaUpload($this->editor, mediaJpeg(900, 600), 'ferry.jpg');
    $page->script(($this->fakeUploads)($ferry));

    $page->attach('[data-test="media-upload-input"]', mediaJpeg(640, 480))
        ->assertPresent('[data-test="media-uploads"] [role="progressbar"][aria-label^="Uploading "]')
        ->assertSee('Uploaded 1 file. Add its alt text.')
        ->assertNotPresent('[data-test="media-uploads"] li')
        ->assertSeeIn('[role="listbox"]', 'ferry');

    expect($page->script('window.__uploads.length'))->toBe(1);
});

it('uploads files dropped anywhere on the screen, and nothing dropped while the details sheet is open', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/media');
    $ferry = mediaUpload($this->editor, mediaJpeg(900, 600), 'ferry.jpg');
    $page->script(($this->fakeUploads)($ferry));

    $page->script(($this->drag)('dragenter'));
    $page->assertSeeIn('[data-test="page-drop"]', 'Drop files to upload');
    $page->script(($this->drag)('drop'));
    $page->assertNotPresent('[data-test="page-drop"]')
        ->assertSee('Uploaded 1 file. Add its alt text.')
        ->assertSeeIn('[role="listbox"]', 'ferry');

    // Below 1024 pixels a file's details are a sheet, which a drop never reaches through.
    $page->resize(800, 900);
    shellSettled($page->click('[role="option"]:has-text("harbour")'), '[data-slot="sheet-content"]');
    $page->script(($this->drag)('dragenter'));
    $page->script(($this->drag)('drop'));
    $page->assertNotPresent('[data-test="page-drop"]');

    expect($page->script('window.__uploads'))->toBe(['quay.png']);
});

it('narrows the library by type, date, the boxes and a search, clears them, and switches layout and opens the trash (I-1316)', function (): void {
    $gone = mediaUpload($this->editor, mediaJpeg(700, 500), 'gone.jpg');
    app(TrashMedia::class)->execute(new MediaActionInput($gone->id, (string) $this->editor->id));
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($this->harbour->id, (string) $this->editor->id, 'harbour', 'Boats at dawn', null, 'Photo: Kemi Adeyemi', 50, 50, 'Staff photo'));
    // The site's clock: Settings › General's zone, which is UTC until someone sets it.
    $month = now()->utc();

    $page = cmSignIn($this->editor)->navigate('/admin/media')
        // No tabs: Trash is a button in the header.
        ->assertPresent('[data-test="media-trash-view"]')
        ->click('[aria-label="Type"]')
        ->click('[data-slot="select-item"] >> text="Documents"')
        ->assertQueryStringHas('type', 'document')
        ->assertSeeIn('[role="listbox"]', 'fares')
        ->assertDontSeeIn('[role="listbox"]', 'harbour')
        ->click('[data-test="media-clear-filters"]')
        ->assertQueryStringMissing('type')
        ->click('[aria-label="Date"]')
        ->click('[data-slot="select-item"] >> text="'.$month->format('F Y').'"')
        ->assertQueryStringHas('month', $month->format('Y-m'))
        ->click('#media-filters-missingAlt')
        ->assertQueryStringHas('missing_alt', '1')
        ->assertSee('Nothing matches these filters')
        ->click('[data-test="media-clear-filters"]')
        ->assertQueryStringMissing('month')
        ->type('[aria-label="Search media"]', 'dawn')
        ->keys('[aria-label="Search media"]', 'Enter')
        ->assertQueryStringHas('search', 'dawn')
        ->assertSeeIn('[role="listbox"]', 'harbour')
        ->assertDontSeeIn('[role="listbox"]', 'fares')
        ->click('[data-test="media-clear-filters"]')
        // The clear lands first: a layout click before it would keep the search, and the PDF with it out of the table.
        ->assertQueryStringMissing('search')
        ->click('[data-test="media-layout-list"]')
        ->assertAttribute('[data-test="media-layout-list"]', 'aria-checked', 'true')
        ->assertScript("[...document.querySelectorAll('table thead th')].map((cell) => cell.textContent.trim()).join('|')", 'File|Type|Alt text|Credit|Used in|Uploaded')
        // A bare tag isn't a selector to Pest's locator (it reads "table" as text), so the table is read in the page.
        ->assertScript("document.querySelector('table')?.innerText.includes('Boats at dawn') === true")
        ->assertScript("document.querySelector('table')?.innerText.includes('Staff photo') === true")
        ->assertScript("document.querySelector('table')?.innerText.includes('Document') === true")
        ->click('[data-test="media-trash-view"]')
        ->assertQueryStringHas('tab', 'trash')
        ->assertScript("document.querySelector('table')?.innerText.includes('gone.jpg') === true")
        ->assertScript("document.querySelector('table')?.innerText.includes('harbour') === false");
});

it('moves a file to the trash after naming the live page that shows it, and Undo restores it (I-166, I-1316)', function (): void {
    listsPublish(refsDraft($this->editor, refsFeatured($this->harbour)), $this->editor);

    $page = cmSignIn($this->editor)->navigate('/admin/media');
    cmWaitUntil($page->click('[role="option"]:has-text("harbour")'), "document.querySelector('[data-test=\"media-detail\"]')?.innerText.includes('harbour') === true && document.querySelector('[data-test=\"media-trash\"]')?.disabled === false")
        ->assertPresent('[data-test="media-trash"]')
        ->click('[data-test="media-trash"]');
    shellSettled($page, '[data-slot="dialog-content"]')
        ->assertSeeIn('[data-slot="dialog-content"]', 'Move “harbour” to the trash?')
        ->assertSeeIn('[data-slot="dialog-content"]', 'It appears on “Pictured”, which is live. That item shows nothing in its place until you restore it.')
        ->click('[data-slot="dialog-content"] button:text-is("Move to trash")')
        ->assertSee('Moved “harbour” to the trash.')
        ->assertNotPresent('[data-test="media-detail"]');

    expect(Media::onlyTrashed()->whereKey($this->harbour->id)->exists())->toBeTrue();

    $page->click('button:text-is("Undo")')
        ->assertSee('Restored. Every item that uses it shows it again.');

    expect(Media::query()->whereKey($this->harbour->id)->exists())->toBeTrue();
});

it('deletes a file permanently only after its dialog, which counts what still refers to it (#46)', function (): void {
    refsDraft($this->editor, refsFeatured($this->harbour));
    app(TrashMedia::class)->execute(new MediaActionInput($this->harbour->id, (string) $this->editor->id));

    $page = cmSignIn($this->editor)->navigate('/admin/media?tab=trash');
    cmWaitUntil($page->click('[role="option"]:has-text("harbour")'), "document.querySelector('[data-test=\"media-detail\"]')?.innerText.includes('harbour') === true && document.querySelector('[data-test=\"media-delete\"]')?.disabled === false")
        ->assertSeeIn('[data-test="media-detail"]', 'In the trash since')
        ->click('[data-test="media-delete"]');
    shellSettled($page, '[data-slot="dialog-content"]')
        ->assertSeeIn('[data-slot="dialog-content"]', 'Delete “harbour” permanently?')
        ->assertSeeIn('[data-slot="dialog-content"]', 'This deletes “harbour.jpg” and every size made from it. 1 item still refers to it and will show nothing in its place. This can’t be undone.')
        ->click('[data-slot="dialog-content"] button:text-is("Cancel")')
        ->assertNotPresent('[data-slot="dialog-content"]');

    expect(Media::withTrashed()->whereKey($this->harbour->id)->exists())->toBeTrue();

    $page->click('[data-test="media-delete"]');
    shellSettled($page, '[data-slot="dialog-content"]')
        ->click('[data-slot="dialog-content"] button:text-is("Delete permanently")')
        ->assertSee('Deleted “harbour.jpg” permanently.')
        ->assertNotPresent('[data-test="media-detail"]');

    expect(Media::withTrashed()->whereKey($this->harbour->id)->exists())->toBeFalse();
});

it('saves the details and the license as each field is left, and says so', function (): void {
    $page = cmSignIn($this->editor)->navigate('/admin/media');
    cmWaitUntil($page->click('[role="option"]:has-text("harbour")'), "document.querySelector('".($this->field)($this->harbour, 'credit')."') !== null")
        ->type(($this->field)($this->harbour, 'credit'), 'Photo: Kemi Adeyemi')
        ->keys(($this->field)($this->harbour, 'credit'), 'Tab')
        ->type(($this->field)($this->harbour, 'licence'), 'Staff photo')
        ->keys(($this->field)($this->harbour, 'licence'), 'Tab')
        ->assertSee('Saved.');
    cmWaitUntil($page, "[...document.querySelectorAll('[data-test=\"flash-toast\"]')].filter((toast) => toast.innerText.includes('Saved.')).length >= 2");

    expect($this->harbour->refresh()->only(['credit', 'licence']))->toBe(['credit' => 'Photo: Kemi Adeyemi', 'licence' => 'Staff photo']);
});

it('shows the modal’s uploads, its details panel always, and a footer that says what is chosen, with Clear and Cancel (I-236)', function (): void {
    $edit = '/admin/content/'.lifecycleDraft($this->editor, 'Harbour news')->id.'/edit';
    $this->actingAs($this->editor)->get($edit)->assertOk();

    $page = cmNavigate(cmSignIn($this->editor), $edit)->click('#editor-featured-choose');
    shellSettled($page, '[data-slot="dialog-content"]')
        ->assertSeeIn('[data-test="media-modal-details"]', 'Select a file to see its details.')
        ->assertSeeIn('[data-test="media-modal-status"]', 'Select a file. Space selects; Enter confirms.')
        ->assertScript("document.querySelector('[data-test=\"media-modal-use\"]').disabled", true)
        ->click('[role="dialog"] [role="option"]:has-text("harbour")')
        ->assertSeeIn('[data-test="media-modal-status"]', '1 file selected')
        ->assertPresent('[data-test="media-modal-details"] '.($this->field)($this->harbour, 'licence'))
        ->click('[data-test="media-modal-clear"]')
        ->assertSeeIn('[data-test="media-modal-status"]', 'Select a file. Space selects; Enter confirms.');

    $ferry = mediaUpload($this->editor, mediaJpeg(900, 600), 'ferry.jpg');
    $page->script(($this->fakeUploads)($ferry));
    $page->attach('[data-test="media-modal-upload-input"]', mediaJpeg(640, 480))
        ->assertSeeIn('[data-test="media-modal-status"]', 'Uploaded 1 file. Add its alt text.')
        ->assertAttribute('[role="dialog"] [role="option"]:has-text("ferry")', 'aria-selected', 'true')
        ->click('[data-test="media-modal-cancel"]')
        ->assertNotPresent('[data-slot="dialog-content"]');
});

it('chooses the file the library already has when an upload is a duplicate and “Use the existing one” is pressed, with its row', function (): void {
    $edit = '/admin/content/'.lifecycleDraft($this->editor, 'Harbour news')->id.'/edit';
    $this->actingAs($this->editor)->get($edit)->assertOk();

    $page = cmNavigate(cmSignIn($this->editor), $edit)->click('#editor-featured-choose');
    shellSettled($page, '[data-slot="dialog-content"]')
        ->assertPresent('[role="dialog"] [role="option"]:has-text("harbour")');

    // Put in the library after the window drew its grid, so only the fetch can bring its tile.
    $quay = mediaUpload($this->editor, mediaJpeg(900, 600), 'quay.jpg');
    $page->script(($this->fakeUploads)($quay, 409));
    $page->attach('[data-test="media-modal-upload-input"]', mediaJpeg(640, 480))
        ->assertSeeIn('[data-test="media-uploads"]', 'Already in the library as “quay”.')
        ->click('[data-test="media-uploads"] button:text-is("Use the existing one")')
        ->assertNotPresent('[data-test="media-uploads"] li')
        ->assertAttribute('[role="dialog"] [role="option"]:has-text("quay")', 'aria-selected', 'true')
        ->assertSeeIn('[data-test="media-modal-status"]', '1 file selected')
        ->click('[data-test="media-modal-use"]')
        ->assertNotPresent('[data-slot="dialog-content"]');
});

it('keeps a caption saved in the picker’s side when the title is saved after it', function (): void {
    $edit = '/admin/content/'.lifecycleDraft($this->editor, 'Harbour news')->id.'/edit';
    $this->actingAs($this->editor)->get($edit)->assertOk();
    $side = fn (string $name): string => '[data-test="media-modal-details"] '.($this->field)($this->harbour, $name);

    // In one attempt: a slow editor left navigate()'s timed-out answer for the click to read (#265).
    $page = cmNavigate(cmSignIn($this->editor), $edit)->click('#editor-featured-choose');
    shellSettled($page, '[data-slot="dialog-content"]')
        ->click('[role="dialog"] [role="option"]:has-text("harbour")');
    cmWaitUntil($page, "document.querySelector('{$side('caption')}') !== null")
        ->type($side('caption'), 'Boats at dawn')
        ->keys($side('caption'), 'Tab');
    cmWaitUntil($page, "[...document.querySelectorAll('[data-test=\"flash-toast\"]')].filter((toast) => toast.innerText.includes('Saved.')).length >= 1")
        ->type($side('title'), 'Harbour at dawn')
        ->keys($side('title'), 'Tab');
    cmWaitUntil($page, "[...document.querySelectorAll('[data-test=\"flash-toast\"]')].filter((toast) => toast.innerText.includes('Saved.')).length >= 2");

    expect($this->harbour->refresh()->only(['caption', 'title']))->toBe(['caption' => 'Boats at dawn', 'title' => 'Harbour at dawn']);
});

it('passes axe on the grid, the list, the details panel and sheet with the confirmation open, and the modal, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);
    $edit = '/admin/content/'.lifecycleDraft($this->editor, 'Harbour news')->id.'/edit';
    $this->actingAs($this->editor)->get($edit)->assertOk();

    // The grid: a listbox whose list items stand aside for its options.
    $page = cmSignIn($this->editor)->navigate('/admin/media')
        ->assertPresent('[role="listbox"] [role="option"]')
        ->assertNoAccessibilityIssues(3);
    $grid = cmSmallTargets($page);

    $page->navigate('/admin/media?view=list')
        ->assertScript("document.querySelector('table')?.innerText.includes('Used in') === true")
        ->assertNoAccessibilityIssues(3);
    $list = cmSmallTargets($page);

    cmWaitUntil($page->click('table button:text-is("harbour")'), "document.querySelector('[data-test=\"media-trash\"]')?.disabled === false")
        ->click('[data-test="media-trash"]');
    // The dialog and its overlay fade in, and axe doesn't retry: it would read their colours mid-fade.
    shellSettled($page, '[data-slot="dialog-content"]')
        ->assertScript("[...document.querySelectorAll('[data-slot=\"dialog-overlay\"], [data-slot=\"dialog-content\"]')].map((element) => getComputedStyle(element).opacity).join(' ')", '1 1')
        ->assertNoAccessibilityIssues(3);
    $drawer = cmSmallTargets($page);

    // Below 1024 pixels the same details are a sheet.
    $page->resize(800, 900);
    cmNavigate($page, '/admin/media?view=list');
    $page->click('table button:text-is("harbour")');
    shellSettled($page, '[data-slot="sheet-content"]');
    shellSettled($page, '[data-slot="sheet-overlay"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"sheet-content\"] [data-test=\"media-trash\"]')?.disabled === false")
        ->click('[data-slot="sheet-content"] [data-test="media-trash"]');
    shellSettled($page, '[data-slot="dialog-content"]')
        ->assertScript("[...document.querySelectorAll('[data-slot=\"dialog-overlay\"], [data-slot=\"dialog-content\"]')].map((element) => getComputedStyle(element).opacity).join(' ')", '1 1')
        ->assertNoAccessibilityIssues(3);
    $sheet = cmSmallTargets($page);
    $page->resize(1280, 900);

    cmNavigate($page, $edit)->click('#editor-featured-choose');
    shellSettled($page, '[data-slot="dialog-content"]')
        ->assertSeeIn('[data-test="media-modal-details"]', 'Select a file to see its details.')
        // The tiles load after the window opens: axe must meet them, not an empty grid.
        ->assertPresent('[data-slot="dialog-content"] [role="option"]');

    expect($grid['small'])->toBe([])
        ->and($grid['checked'])->toBeGreaterThan(0)
        ->and($list['small'])->toBe([])
        ->and($list['checked'])->toBeGreaterThan(0)
        ->and($drawer['small'])->toBe([])
        ->and($drawer['checked'])->toBeGreaterThan(0)
        ->and($sheet['small'])->toBe([])
        ->and($sheet['checked'])->toBeGreaterThan(0)
        ->and(($this->axeIn)($page, '[data-slot="dialog-content"]'))->toBe([]);
})->with(['light', 'dark']);
