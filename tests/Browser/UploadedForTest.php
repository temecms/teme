<?php

declare(strict_types=1);

use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\UploadMedia;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');

    // Each request starts afresh, as on a real server (MediaScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    $this->draft = lifecycleDraft($this->editor, 'Harbour news');
    $this->other = lifecycleDraft($this->editor, 'Ferry times');
    $this->harbour = ufFile($this->editor, 'harbour.jpg', 1200, $this->draft->id);
    $this->travel(1)->minutes();
    $this->quay = ufFile($this->editor, 'quay.jpg', 1100, null);
    $this->travel(1)->minutes();

    // Warm the screens before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get('/admin/media')->assertOk();
    $this->actingAs($this->editor)->get("/admin/content/{$this->other->id}/edit")->assertOk();
});

/**
 * A file $actor puts in the library, uploaded for $item, through the real
 * Operation; each width makes another file.
 */
function ufFile(User $actor, string $name, int $width, ?string $item): Media
{
    return app(UploadMedia::class)->execute(new UploadMediaInput(mediaJpeg($width, 600), $name, (string) $actor->id, uploadedFor: $item))->refresh();
}

/**
 * The page's stand-in for the upload request, as MediaScreenTest's: Pest's
 * in-process server takes no files, so POST /admin/media is answered in the
 * page with a 201 naming $answer, and every other request goes on as usual.
 * window.__uploadedFor lists what each upload sent as uploaded_for ('' for nothing).
 */
function ufFakeUploads(Media $answer): string
{
    return str_replace('__BODY__', json_encode(['id' => $answer->id], JSON_THROW_ON_ERROR), <<<'JS'
        (() => {
            const Real = window.XMLHttpRequest;
            window.__uploadedFor = [];

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

                    window.__uploadedFor.push(body.get('uploaded_for') ?? '');
                    window.setTimeout(() => {
                        Object.defineProperty(this, 'status', { value: 201 });
                        Object.defineProperty(this, 'responseText', { value: JSON.stringify(__BODY__) });
                        this.dispatchEvent(new ProgressEvent('load'));
                    }, 300);
                }
            }

            window.XMLHttpRequest = StandIn;

            return true;
        })()
        JS);
}

/**
 * axe on one part of the page, target sizes included, as MediaScreenTest's:
 * the editor around the media modal is held to axe elsewhere.
 *
 * @return list<string>
 */
function ufAxeIn(mixed $page, string $selector): array
{
    return cmScriptArray($page, str_replace('__SELECTOR__', json_encode($selector, JSON_THROW_ON_ERROR), <<<'JS'
        async () => {
            const run = await window.axe.run(document.querySelector(__SELECTOR__), { rules: { 'target-size': { enabled: true } } });

            return run.violations.map((violation) => violation.id);
        }
        JS));
}

/**
 * Waits until the sheet or dialog part $selector is there and its own
 * animations have ended, then holds it to that: shellSettled() looks once,
 * so right after a click it would race the opening.
 */
function ufSettled(mixed $page, string $selector): mixed
{
    $quoted = json_encode($selector, JSON_THROW_ON_ERROR);
    cmWaitUntil($page, "[...document.querySelectorAll({$quoted})].some((element) => element.getAnimations().length === 0)");

    return shellSettled($page, $selector);
}

it('narrows the editor’s media modal to the files uploaded for the item, records the item on files uploaded there, and opens again with the box clear (I-553)', function (): void {
    $options = "document.querySelectorAll('[data-slot=\"dialog-content\"] [role=\"option\"]')";
    $box = "document.querySelector('#media-modal-filters-uploadedFor')";

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate("/admin/content/{$this->other->id}/edit"));
    $page->click('#editor-featured-choose');
    ufSettled($page, '[data-slot="dialog-content"]');
    cmWaitUntil($page, "{$options}.length === 2");
    $page->assertSeeIn('[data-test="media-filters"]', 'Uploaded for this post');

    // Nothing has been uploaded for this post yet: harbour is the other post's, quay nobody's.
    $page->click('#media-modal-filters-uploadedFor');
    cmWaitUntil($page, "{$options}.length === 0 && document.querySelector('[data-slot=\"dialog-content\"]').innerText.includes('Nothing has been uploaded for this post yet.')");

    // Put in the library for this post after the window drew its grid, so only the reload can show it.
    $ferry = ufFile($this->editor, 'ferry.jpg', 900, $this->other->id);
    $page->script(ufFakeUploads($ferry));
    $page->attach('[data-test="media-modal-upload-input"]', mediaJpeg(640, 480));
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-modal-status\"]').innerText.includes('Uploaded 1 file. Add its alt text.')");
    cmWaitUntil($page, "{$options}.length === 1 && {$options}[0].innerText.includes('ferry')");
    // The chip stays pressed after the batch: every file uploaded here was uploaded for this post.
    cmWaitUntil($page, "{$box}.getAttribute('aria-pressed') === 'true'");

    expect($page->script('window.__uploadedFor'))->toBe([$this->other->id]);

    // The featured image's window stays mounted between openings: opened again, it starts with the chip up.
    $page->click('[data-test="media-modal-cancel"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"dialog-content\"]') === null");
    $page->click('#editor-featured-choose');
    ufSettled($page, '[data-slot="dialog-content"]');
    cmWaitUntil($page, "{$box}.getAttribute('aria-pressed') === 'false' && {$options}.length === 3");
});

it('records the item on a pasted image brought into the library by Import images (I-453)', function (): void {
    Sleep::fake();
    dlServe(['photos.example' => ['93.184.216.34']], [
        // A closure: each download needs a body of its own.
        'photos.example/*' => fn () => Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    // Pastes HTML into the body as a browser does from the clipboard (ImportImagesTest).
    $paste = '(() => { const body = document.querySelector(".teme-prose"); body.focus(); const data = new DataTransfer(); data.setData("text/html", '
        .json_encode('<p>Gates open at six.</p><figure class="wp-block-image"><img src="https://photos.example/2026/ferry.jpg" alt="The ferry at the quay"><figcaption>Morning</figcaption></figure>', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        .'); body.dispatchEvent(new ClipboardEvent("paste", { clipboardData: data, bubbles: true, cancelable: true })); return true; })()';

    $page = cmEditorSettled(cmSignIn($this->editor)->navigate("/admin/content/{$this->draft->id}/edit"));
    $page->script($paste);
    cmWaitUntil($page, "document.querySelector('[data-pasted-image]') !== null");
    $page->click('[data-pasted-image] button:text-is("Import")');
    cmWaitUntil($page, "document.querySelector('[data-pasted-image]') === null");

    expect(Media::query()->where('source_url', 'https://photos.example/2026/ferry.jpg')->sole()->uploaded_for)->toBe($this->draft->id);
});

it('says in the media drawer what a file was uploaded for, and narrows the library to it from there', function (): void {
    $options = "document.querySelectorAll('[role=\"listbox\"] [role=\"option\"]')";
    $line = "document.querySelector('[data-test=\"media-uploaded-for\"]')";

    $page = cmSignIn($this->editor)->navigate('/admin/media');
    cmWaitUntil($page, "{$options}.length === 2");
    $page->click('[role="option"]:has-text("harbour")');
    ufSettled($page, '[data-test="media-detail"]');
    cmWaitUntil($page, "{$line}?.innerText.includes('Uploaded for') === true && {$line}.innerText.includes('Harbour news — draft')");

    $page->click('[data-test="media-uploaded-for"] a');
    cmWaitUntil($page, "new URL(location.href).searchParams.get('uploaded_for') === '{$this->draft->id}' && document.querySelector('[data-test=\"media-detail\"]') === null");
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-uploaded-for-chip\"]')?.innerText.includes('Uploaded for “Harbour news”') === true");
    cmWaitUntil($page, "{$options}.length === 1 && {$options}[0].innerText.includes('harbour')");

    $page->click('[data-test="media-uploaded-for-chip"] button');
    cmWaitUntil($page, "!new URL(location.href).searchParams.has('uploaded_for') && {$options}.length === 2");

    $page->click('[role="option"]:has-text("quay")');
    ufSettled($page, '[data-test="media-detail"]');
    cmWaitUntil($page, "{$line}?.innerText.includes('Not uploaded for any item.') === true");
    $page->assertNotPresent('[data-test="media-uploaded-for"] a');
});

it('passes axe on the library’s chip, the drawer’s line and the modal’s box, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)->navigate("/admin/media?uploaded_for={$this->draft->id}");
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-uploaded-for-chip\"]') !== null");
    $page->assertNoAccessibilityIssues(3);
    $chip = cmSmallTargets($page);

    $page->click('[role="option"]:has-text("harbour")');
    ufSettled($page, '[data-test="media-detail"]');
    cmWaitUntil($page, "document.querySelector('[data-test=\"media-uploaded-for\"] a') !== null");
    $page->assertNoAccessibilityIssues(3);
    $drawer = cmSmallTargets($page);

    $page->navigate("/admin/content/{$this->draft->id}/edit");
    cmEditorSettled($page)->click('#editor-featured-choose');
    ufSettled($page, '[data-slot="dialog-content"]');
    cmWaitUntil($page, "document.querySelectorAll('[data-slot=\"dialog-content\"] [role=\"option\"]').length === 2");
    $page->click('#media-modal-filters-uploadedFor');
    cmWaitUntil($page, "document.querySelectorAll('[data-slot=\"dialog-content\"] [role=\"option\"]').length === 1");

    expect($chip['small'])->toBe([])
        ->and($chip['checked'])->toBeGreaterThan(0)
        ->and($drawer['small'])->toBe([])
        ->and($drawer['checked'])->toBeGreaterThan(0)
        ->and(ufAxeIn($page, '[data-slot="dialog-content"]'))->toBe([]);
})->with(['light', 'dark']);
