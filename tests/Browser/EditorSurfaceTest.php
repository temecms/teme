<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// The writing surface on the design system (docs/architecture/design-system.md, 4.39):
// the title and excerpt with their labels shown while focused, the counts
// under the body, the reading measure, the toolbar and the selection bubble.

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');

    // A paragraph long enough to fill the measure, an image card, and a short paragraph.
    $this->draft = docSave(lifecycleDraft($this->editor, 'Harbour news'), $this->editor, docWith(
        docText(trim(str_repeat('The ferry leaves the harbour at nine, and the island waits for it every morning. ', 5))),
        pageImageNode(pageImage($this->editor, 1600, 900), (string) Str::ulid()),
        docText('Fares rise on Monday.'),
    ));
    $this->edit = "/admin/content/{$this->draft->id}/edit";
    $this->empty = lifecycleDraft($this->editor, 'Empty note');

    // Selects text in a top-level block as a drag would, counting characters of text only.
    $this->esfSelect = fn (int $block, int $from, int $to): string => <<<JS
        (() => {
            const element = document.querySelectorAll('.teme-prose > *')[{$block}];
            const point = (offset) => {
                const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
                let node = walker.nextNode();
                while (node !== null && offset > node.length) {
                    offset -= node.length;
                    node = walker.nextNode();
                }
                return [node, offset];
            };
            document.querySelector('.teme-prose').focus();
            const range = document.createRange();
            range.setStart(...point({$from}));
            range.setEnd(...point({$to}));
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        })()
        JS;

    // Whether the selection bubble is on screen.
    $this->esfBubble = "(() => { const bubble = document.querySelector('[data-slot=\"bubble-menu\"]'); if (bubble === null) { return false; } const box = bubble.getBoundingClientRect(); return box.width > 0 && getComputedStyle(bubble.parentElement).visibility !== 'hidden' && getComputedStyle(bubble.parentElement).display !== 'none'; })()";

    // A label is drawn when it takes room on the screen; sr-only takes one pixel at most.
    $this->esfLabelShown = fn (string $for): string => "(() => { const box = document.querySelector('label[for=\"{$for}\"]').getBoundingClientRect(); return box.width > 1 && box.height > 1; })()";

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

it('shows Title\'s and Excerpt\'s labels only while they are focused, and keeps their ids', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->assertPresent('#editor-title')
        ->assertPresent('#editor-excerpt')
        ->assertScript(($this->esfLabelShown)('editor-title'), false)
        ->assertScript(($this->esfLabelShown)('editor-excerpt'), false)
        // Named by its label either way.
        ->assertScript("document.getElementById('editor-title').labels[0].textContent.trim()", 'Title')
        ->assertScript("document.getElementById('editor-excerpt').labels[0].textContent.trim() !== ''");

    $page->script("document.getElementById('editor-title').focus()");
    cmWaitUntil($page, ($this->esfLabelShown)('editor-title'));
    $page->assertScript(($this->esfLabelShown)('editor-excerpt'), false);

    $page->script("document.getElementById('editor-excerpt').focus()");
    cmWaitUntil($page, ($this->esfLabelShown)('editor-excerpt'));
    $page->assertScript(($this->esfLabelShown)('editor-title'), false);
});

it('counts words, characters and reading time under the body as it is typed', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), "/admin/content/{$this->empty->id}/edit"));

    $page->type('.teme-prose', 'One two three four five six seven eight nine ten');

    cmWaitUntil($page, "(document.querySelector('[data-slot=\"editor-body\"] [data-test=\"body-counts\"]')?.innerText ?? '').includes('Words\\n10') || (document.querySelector('[data-slot=\"editor-body\"] [data-test=\"body-counts\"] dd')?.textContent === '10')");
    $page->assertSeeIn('[data-test="body-counts"]', 'Characters')
        ->assertSeeIn('[data-test="body-counts"]', 'Reading time')
        ->assertSeeIn('[data-test="body-counts"]', '1 min');
});

it('keeps paragraphs at the reading measure and figures at the column\'s width', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1440, 900), $this->edit));
    cmWaitUntil($page, "document.querySelector('.teme-prose > .node-image') !== null");

    $at = cmScriptArray($page, <<<'JS'
        () => {
            const prose = document.querySelector('.teme-prose');
            const style = getComputedStyle(prose);

            return {
                paragraph: document.querySelector('.teme-prose > p').getBoundingClientRect().width,
                image: document.querySelector('.teme-prose > .node-image').getBoundingClientRect().width,
                inner: prose.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight),
            };
        }
        JS);

    expect($at['paragraph'])->toBeLessThanOrEqual(704)
        ->and($at['image'])->toEqualWithDelta($at['inner'], 1);
});

it('does not open the selection bubble on touch', function (): void {
    $page = visit('/admin/login')
        ->on()->mobile()
        ->resize(1280, 800)
        ->type('email', $this->editor->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin');
    $page = cmEditorSettled(cmNavigate($page, $this->edit));

    $page->assertScript("window.matchMedia('(pointer: coarse)').matches");
    $page->script(($this->esfSelect)(2, 0, 5));
    $page->wait(0.5);
    $page->assertScript($this->esfBubble, false);
});

it('opens the selection bubble with a mouse', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->script(($this->esfSelect)(2, 0, 5));
    cmWaitUntil($page, $this->esfBubble);
});

it('offers Underline, Superscript, Subscript and Clear formatting under the bubble\'s More', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->script(($this->esfSelect)(2, 0, 5));
    cmWaitUntil($page, $this->esfBubble);
    $page->click('[data-slot="bubble-menu"] [aria-label="More"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"bubble-menu\"] [role=\"menu\"]') !== null");

    expect(cmScriptArray($page, "Array.from(document.querySelectorAll('[data-slot=\"bubble-menu\"] [role=\"menu\"] [role^=\"menuitem\"]'), (item) => item.textContent.trim())"))
        ->toBe(['Underline', 'Superscript', 'Subscript', 'Clear formatting']);

    $page->click('[data-slot="bubble-menu"] [role="menu"] [role^="menuitem"]:has-text("Underline")');
    cmWaitUntil($page, "document.querySelector('.teme-prose > p:last-child u')?.textContent === 'Fares'");
});

it('passes axe in light and dark with every target 24 pixels or more', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 900), $this->edit))
        // The editor folds the admin sidebar as it opens; axe reads its labels once they have faded.
        ->assertScript("[...document.querySelectorAll('[data-sidebar=\"group-label\"]')].flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length", 0);

    expect(cmAxeViolations($page))->toBe([]);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);
