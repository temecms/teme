<?php

declare(strict_types=1);

use App\Domain\Contents\Models\ContentAutosave;
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

    // Each request starts afresh, as on a real server (TwoEditorsTest); autosaves are counted.
    $this->autosaves = 0;
    Event::listen(RequestHandled::class, function (RequestHandled $event): void {
        if ($event->request->isMethod('POST') && str_ends_with($event->request->path(), '/autosave')) {
            $this->autosaves++;
        }

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

    // The image card (the second block), its header, and whether the header is on screen.
    $this->esfCard = "document.querySelectorAll('.teme-prose > *')[1]";
    $this->esfHeadShown = "(() => { const head = document.querySelectorAll('.teme-prose > *')[1].querySelector('[data-slot=\"card-head\"]'); return head !== null && getComputedStyle(head).visibility !== 'hidden' && head.getBoundingClientRect().height > 0; })()";

    // The image card's attributes in this person's autosave.
    $this->esfSavedImage = fn (): array => collect(ContentAutosave::query()->where('content_id', $this->draft->id)->value('document')['content'] ?? [])->firstWhere('type', 'image')['attrs'] ?? [];

    // Waits in the browser until a save newer than $before has landed.
    $this->esfSavedSince = fn (mixed $page, mixed $before): mixed => cmWaitUntil($page, "(() => { const state = document.querySelector('[data-test=\"save-state\"]'); return state.dataset.state === 'saved' && (state.dataset.savedAt ?? null) !== ".json_encode($before, JSON_THROW_ON_ERROR).'; })()');

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

it('counts words, characters and reading time under the body as it is typed, each number first', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), "/admin/content/{$this->empty->id}/edit"));

    $page->type('.teme-prose', 'One two three four five six seven eight nine ten');

    cmWaitUntil($page, "(document.querySelector('[data-slot=\"editor-body\"] [data-test=\"body-counts\"]')?.innerText ?? '').includes('10 words')");
    $page->assertSeeIn('[data-test="body-counts"]', '48 characters')
        ->assertSeeIn('[data-test="body-counts"]', '1 min read')
        ->assertScript("[...document.querySelectorAll('[data-test=\"body-counts\"] strong')].map((number) => number.textContent).join('|')", '10|48|1');
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

it('shows a card\'s header on hover, when selected and while focus is inside', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    // At rest: the pointer on the title, the caret in the last paragraph.
    $page->hover('#editor-title');
    $page->script("(() => { const body = document.querySelector('.teme-prose'); body.focus(); const text = document.createTreeWalker(body.children[2], NodeFilter.SHOW_TEXT).nextNode(); const range = document.createRange(); range.setStart(text, 2); range.collapse(true); getSelection().removeAllRanges(); getSelection().addRange(range); })()");
    cmWaitUntil($page, "!{$this->esfHeadShown}");

    $page->hover('.teme-prose > *:nth-child(2) img');
    cmWaitUntil($page, $this->esfHeadShown);
    $page->hover('#editor-title');
    cmWaitUntil($page, "!{$this->esfHeadShown}");

    // Selected: shown with the pointer elsewhere.
    $page->click('.teme-prose > *:nth-child(2) img');
    $page->hover('#editor-title');
    cmWaitUntil($page, "{$this->esfCard}.classList.contains('ProseMirror-selectednode') && {$this->esfHeadShown}");

    // Not selected, but focus inside the card.
    $page->script("(() => { const body = document.querySelector('.teme-prose'); body.focus(); const text = document.createTreeWalker(body.children[2], NodeFilter.SHOW_TEXT).nextNode(); const range = document.createRange(); range.setStart(text, 2); range.collapse(true); getSelection().removeAllRanges(); getSelection().addRange(range); })()");
    cmWaitUntil($page, "!{$this->esfHeadShown}");
    $page->script("{$this->esfCard}.querySelector('input[placeholder=\"Add a caption\"]').focus()");
    cmWaitUntil($page, $this->esfHeadShown);
});

it('collapses a card to one line and opens it again, saving nothing', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    $saves = $this->autosaves;

    $page->hover('.teme-prose > *:nth-child(2) img');
    cmWaitUntil($page, $this->esfHeadShown);
    $page->click('.teme-prose > *:nth-child(2) [data-slot="card-head"] button[aria-label="Collapse"]');

    // One line: the name and no picture; the header stays.
    cmWaitUntil($page, "{$this->esfCard}.querySelector('img') === null && {$this->esfCard}.getBoundingClientRect().height <= 64 && {$this->esfCard}.textContent.includes('Image')");
    $page->hover('#editor-title');
    cmWaitUntil($page, $this->esfHeadShown);

    // Longer than the autosave's idle wait: nothing was sent.
    $page->script('new Promise((resolve) => setTimeout(resolve, 3000))');
    expect($this->autosaves)->toBe($saves);

    $page->click('.teme-prose > *:nth-child(2) [data-slot="card-head"] button[aria-label="Expand"]');
    cmWaitUntil($page, "{$this->esfCard}.querySelector('img') !== null");

    // The block menu does the same.
    $page->hover('.teme-prose > *:nth-child(2) img');
    cmWaitUntil($page, "(() => { const grip = document.querySelector('[data-block-grip=\"pointer\"]'); return grip !== null && getComputedStyle(grip.closest('.teme-block-handle')).visibility !== 'hidden' && grip.getAttribute('aria-label') === 'Block menu: Image'; })()");
    $page->click('[data-block-grip="pointer"]');
    $page->click('[role="menu"] [role="menuitem"]:has-text("Collapse to one line")');
    cmWaitUntil($page, "{$this->esfCard}.querySelector('img') === null");

    $page->script('new Promise((resolve) => setTimeout(resolve, 3000))');
    expect($this->autosaves)->toBe($saves);
});

it('types an image\'s caption in place and shows the alt-text row when alt text is missing', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $before = $page->script("document.querySelector('[data-test=\"save-state\"]').dataset.savedAt ?? null");
    $page->assertScript("{$this->esfCard}.textContent.includes('Alt text missing')");

    $page->click('.teme-prose > *:nth-child(2) input[placeholder="Add a caption"]');
    $page->page()->locator(':focus')->type('Ferry at dawn');
    ($this->esfSavedSince)($page, $before);
    cmWaitUntil($page, "document.querySelector('[data-test=\"save-state\"]').dataset.state === 'saved'");
    expect(($this->esfSavedImage)()['caption'] ?? null)->toBe('Ferry at dawn');

    $before = $page->script("document.querySelector('[data-test=\"save-state\"]').dataset.savedAt ?? null");
    $page->click('.teme-prose > *:nth-child(2) [data-slot="card-alt"] input');
    $page->page()->locator(':focus')->type('The morning ferry');
    ($this->esfSavedSince)($page, $before);

    expect(($this->esfSavedImage)())->toMatchArray(['caption' => 'Ferry at dawn', 'alt' => 'The morning ferry']);
    $page->assertScript("{$this->esfCard}.textContent.includes('Alt text missing')", false);
});

it('passes axe with a card selected, light and dark', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 900), $this->edit))
        ->assertScript("[...document.querySelectorAll('[data-sidebar=\"group-label\"]')].flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length", 0);

    $page->click('.teme-prose > *:nth-child(2) img');
    cmWaitUntil($page, "{$this->esfCard}.classList.contains('ProseMirror-selectednode') && {$this->esfHeadShown}");
    cmWaitUntil($page, "document.getAnimations().every((animation) => animation.playState !== 'running')");

    expect(cmAxeViolations($page))->toBe([]);
})->with(['light', 'dark']);

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

it('compares two revisions with added and removed marked in words as well as color', function (): void {
    $before = (string) $this->draft->working_revision_id;
    $after = docSave($this->draft, $this->editor, docWith(
        docText('The ferry leaves at ten.'),
        docText('Fares rise on Monday.'),
    ));
    $compare = "/admin/content/{$this->draft->id}/compare?from={$before}&to={$after->working_revision_id}";
    $this->actingAs($this->editor)->get($compare)->assertOk();

    $page = cmNavigate(cmSignIn($this->editor), $compare);
    cmWaitUntil($page, "document.querySelector('[aria-label=\"Changes\"] li[data-kind]') !== null");

    // Every change says what it is in words: struck or marked words, or the block's type with what happened to it.
    $page->assertScript("(() => { const changes = Array.from(document.querySelectorAll('[aria-label=\"Changes\"] li[data-kind]')); return changes.length > 0 && changes.every((item) => item.querySelector('ins, del') !== null || /ADDED|REMOVED|MOVED/.test(item.innerText)); })()")
        ->assertScript("document.querySelector('[data-slot=\"editor-shell\"]') === null");
});

it('opens the rail over the writing from the top bar on a tablet, and selecting a card does not open it', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(900, 1000), $this->edit);
    cmWaitUntil($page, "document.querySelector('.teme-prose') !== null && document.getAnimations().every((animation) => !(animation instanceof CSSTransition) || animation.playState !== 'running')");

    // A card selected: the rail stays closed, the writing in view.
    $page->click('.teme-prose > *:nth-child(2) img');
    cmWaitUntil($page, "{$this->esfCard}.classList.contains('ProseMirror-selectednode')");
    $page->wait(0.5);
    $page->assertScript("document.querySelector('[data-slot=\"sheet-content\"]') === null");

    // From the top bar, the rail is a sheet over the writing.
    $page->click('[data-test="rail-toggle"]');
    cmWaitUntil($page, "document.querySelector('[data-slot=\"sheet-content\"]') !== null");
});
