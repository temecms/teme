<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentSchema;
use App\Domain\Contents\Models\ContentAutosave;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// The handle before each block, the block menu and the block keys:
// moving, duplicating, turning and deleting a block, every one keeping the
// block ids' rule (I-3362).

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

    // Three paragraphs and an image card, each with an id known here.
    $this->ids = [
        'one' => '01K5Z9P2QH7N3X4C8V6B0M1T3A',
        'two' => '01K5Z9P2QH7N3X4C8V6B0M1T3B',
        'three' => '01K5Z9P2QH7N3X4C8V6B0M1T3C',
        'image' => '01K5Z9P2QH7N3X4C8V6B0M1T3D',
    ];
    $paragraph = fn (string $id, string $text): array => ['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [['type' => 'text', 'text' => $text]]];
    $this->image = pageImage($this->editor, 1600, 900);
    $this->item = docSave(lifecycleDraft($this->editor, 'Harbour news'), $this->editor, docWith(
        $paragraph($this->ids['one'], 'One'),
        $paragraph($this->ids['two'], 'Two'),
        $paragraph($this->ids['three'], 'Three'),
        pageImageNode($this->image, $this->ids['image']),
    ));
    $this->edit = "/admin/content/{$this->item->id}/edit";

    // Each paragraph with words, as [words, data-id]; the empty line the editor keeps after a card is left out.
    $this->ebkBlocks = fn (mixed $page): array => cmScriptArray($page, "Array.from(document.querySelectorAll('.teme-prose > p'), (block) => [block.textContent, block.getAttribute('data-id')]).filter(([words]) => words !== '')");

    // Whether the paragraphs with words read so, in order.
    $this->ebkOrder = fn (string ...$words): string => "Array.from(document.querySelectorAll('.teme-prose > p'), (block) => block.textContent).filter((words) => words !== '').join() === ".json_encode(implode(',', $words), JSON_THROW_ON_ERROR);

    // Puts the caret after the first letter of a top-level block. Not at its start: just
    // after the editor takes focus, ProseMirror reads a caret at the document's very start
    // as the browser's own reset, and puts its selection back.
    $this->ebkCaret = fn (int $block): string => <<<JS
        (() => {
            const body = document.querySelector('.teme-prose');
            body.focus();
            const text = document.createTreeWalker(body.children[{$block}], NodeFilter.SHOW_TEXT).nextNode();
            const range = document.createRange();
            range.setStart(text, 1);
            range.collapse(true);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            return true;
        })()
        JS;

    // The words of the top-level block the caret is in, while the writing surface has focus.
    $this->ebkCaretIn = "(() => { const node = window.getSelection().anchorNode; const element = node?.nodeType === 1 ? node : node?.parentElement; return document.activeElement === document.querySelector('.teme-prose') && element !== null ? element.closest('.teme-prose > *')?.textContent ?? null : null; })()";

    // The focused element's accessible name.
    $this->ebkFocused = "document.activeElement?.getAttribute('aria-label') ?? ''";

    // An item of the open block menu, by the words it starts with.
    $this->ebkItem = fn (string $words): string => "Array.from(document.querySelectorAll('[role=\"menu\"] [role^=\"menuitem\"]')).find((item) => item.textContent.trim().startsWith(".json_encode($words, JSON_THROW_ON_ERROR).'))';

    // The top-level nodes in this person's autosave, an empty line left out.
    $this->ebkSaved = fn (): array => array_values(array_filter(
        (array) (ContentAutosave::query()->where('content_id', $this->item->id)->value('document')['content'] ?? []),
        fn (array $node): bool => $node['type'] !== 'paragraph' || ($node['content'] ?? []) !== [],
    ));

    // When the editor last saved, as the save state shows it.
    $this->ebkSavedAt = fn (mixed $page): mixed => $page->script("document.querySelector('[data-test=\"save-state\"]').dataset.savedAt ?? null");

    // Waits in the browser until a save newer than $before has landed (the server answers in this process), then reads it.
    $this->ebkSavedSince = function (mixed $page, mixed $before): array {
        cmWaitUntil($page, "(() => { const state = document.querySelector('[data-test=\"save-state\"]'); return state.dataset.state === 'saved' && (state.dataset.savedAt ?? null) !== ".json_encode($before, JSON_THROW_ON_ERROR).'; })()');

        return ($this->ebkSaved)();
    };

    // Opens the block menu of the block the caret is in, by keyboard: Tab, then Enter.
    $this->ebkOpenMenu = function (mixed $page): mixed {
        $page->keys('.teme-prose', 'Tab');
        cmWaitUntil($page, "(document.activeElement?.getAttribute('aria-label') ?? '').startsWith('Block menu: ')");
        $page->page()->locator(':focus')->press('Enter');

        // Open, with its first item focused.
        return cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') !== null && (document.activeElement?.getAttribute('role') ?? '').startsWith('menuitem')");
    };

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

it('moves a paragraph down with Alt Shift ↓ and back up, keeping its id, and saves the new order', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $before = ($this->ebkSavedAt)($page);
    $page->script(($this->ebkCaret)(0));
    $page->keys('.teme-prose', 'Alt+Shift+ArrowDown');
    cmWaitUntil($page, ($this->ebkOrder)('Two', 'One', 'Three'));

    $blocks = ($this->ebkBlocks)($page);
    $saved = ($this->ebkSavedSince)($page, $before);

    expect(array_column($blocks, 1))->toBe([$this->ids['two'], $this->ids['one'], $this->ids['three']])
        ->and(array_column($saved, 'type'))->toBe(['paragraph', 'paragraph', 'paragraph', 'image'])
        ->and(array_column(array_column($saved, 'attrs'), 'id'))->toBe([$this->ids['two'], $this->ids['one'], $this->ids['three'], $this->ids['image']]);

    $page->keys('.teme-prose', 'Alt+Shift+ArrowUp');
    cmWaitUntil($page, ($this->ebkOrder)('One', 'Two', 'Three'));
    expect(array_column(($this->ebkBlocks)($page), 1))->toBe([$this->ids['one'], $this->ids['two'], $this->ids['three']]);
});

it('moves a block from the block menu by keyboard alone: Tab to the grip, Enter, Move down', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->script(($this->ebkCaret)(1));
    ($this->ebkOpenMenu)($page);

    // The menu opens on its first item; down to Move down.
    for ($step = 0; $step < 8; $step++) {
        $focused = $page->script('document.activeElement?.textContent.trim()');

        if (str_starts_with((string) $focused, 'Move down')) {
            break;
        }

        $page->page()->locator(':focus')->press('ArrowDown');
        cmWaitUntil($page, 'document.activeElement?.textContent.trim() !== '.json_encode($focused, JSON_THROW_ON_ERROR));
    }

    $page->page()->locator(':focus')->press('Enter');
    cmWaitUntil($page, ($this->ebkOrder)('One', 'Three', 'Two'));
    cmWaitUntil($page, "{$this->ebkCaretIn} === 'Two'");

    // Back at the top: Move up is not offered on the first block.
    $page->script(($this->ebkCaret)(0));
    ($this->ebkOpenMenu)($page);
    $page->assertScript(($this->ebkItem)('Move up').".getAttribute('aria-disabled')", 'true')
        ->assertScript(($this->ebkItem)('Move down').".getAttribute('aria-disabled')", null);
});

it('drags a block by its grip, and it keeps its id', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->hover('.teme-prose > p:nth-child(3)');
    cmWaitUntil($page, "(() => { const grip = document.querySelector('[data-block-grip=\"pointer\"]'); return grip !== null && getComputedStyle(grip.closest('.teme-block-handle')).visibility !== 'hidden' && grip.getAttribute('aria-label') === 'Block menu: Paragraph'; })()");

    $page->page()->locator('[data-block-grip="pointer"]')->dragTo(
        $page->page()->locator('.teme-prose > p:nth-child(1)'),
        ['targetPosition' => ['x' => 8, 'y' => 2]],
    );

    cmWaitUntil($page, ($this->ebkOrder)('Three', 'One', 'Two'));
    expect(array_column(($this->ebkBlocks)($page), 1))->toBe([$this->ids['three'], $this->ids['one'], $this->ids['two']]);
});

it('duplicates a block with Ctrl D, and the copy gets a new id', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->script(($this->ebkCaret)(0));
    $page->keys('.teme-prose', 'Control+d');
    cmWaitUntil($page, ($this->ebkOrder)('One', 'One', 'Two', 'Three'));

    $blocks = ($this->ebkBlocks)($page);

    expect($blocks[0][1])->toBe($this->ids['one'])
        ->and($blocks[1][1])->not->toBeIn($this->ids)->toMatch(DocumentSchema::NODE_ID);
});

it('duplicates an image card from the block menu, the copy with a new id and the same picture', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    cmWaitUntil($page, "document.querySelector('.teme-prose > .node-image') !== null");

    $before = ($this->ebkSavedAt)($page);
    $page->hover('.teme-prose > .node-image');
    cmWaitUntil($page, "(() => { const grip = document.querySelector('[data-block-grip=\"pointer\"]'); return grip !== null && getComputedStyle(grip.closest('.teme-block-handle')).visibility !== 'hidden' && grip.getAttribute('aria-label') === 'Block menu: Image'; })()");
    $page->click('[data-block-grip="pointer"]');
    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') !== null");
    $page->script(($this->ebkItem)('Duplicate').'.click()');

    $saved = ($this->ebkSavedSince)($page, $before);
    $images = array_values(array_filter($saved, fn (array $node): bool => $node['type'] === 'image'));

    expect(array_column($saved, 'type'))->toBe(['paragraph', 'paragraph', 'paragraph', 'image', 'image'])
        ->and($images[0]['attrs']['id'])->toBe($this->ids['image'])
        ->and($images[1]['attrs']['id'])->not->toBeIn($this->ids)->toMatch(DocumentSchema::NODE_ID)
        ->and($images[1]['attrs']['mediaId'])->toBe($this->image->id);
});

it('turns a paragraph into a heading from the block menu', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->script(($this->ebkCaret)(1));
    ($this->ebkOpenMenu)($page);
    $page->click('[role="menu"] [role="menuitem"]:has-text("Turn into")');
    cmWaitUntil($page, "document.querySelectorAll('[role=\"menu\"]').length === 2");
    $page->script("Array.from(document.querySelectorAll('[role=\"menu\"]')[1].querySelectorAll('[role=\"menuitem\"]')).find((item) => item.textContent.trim() === 'Heading').click()");

    cmWaitUntil($page, "document.querySelector('.teme-prose > h2')?.textContent === 'Two'");
    $page->assertScript("document.querySelector('.teme-prose > h2').getAttribute('data-id')", $this->ids['two']);
});

it('deletes a block from the block menu and puts focus on the next block', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    $page->script(($this->ebkCaret)(1));
    ($this->ebkOpenMenu)($page);
    $page->script(($this->ebkItem)('Delete').'.click()');

    cmWaitUntil($page, ($this->ebkOrder)('One', 'Three'));
    cmWaitUntil($page, "{$this->ebkCaretIn} === 'Three'");
});

it('shows the current block\'s handle on touch, at 44 pixels, with no + between blocks', function (): void {
    $page = visit('/admin/login')
        ->on()->mobile()
        ->resize(1280, 800)
        ->type('email', $this->editor->email)
        ->type('password', 'password')
        ->click('[data-test="login-button"]')
        ->assertPathIs('/admin');
    $page = cmEditorSettled(cmNavigate($page, $this->edit));

    $page->assertScript("window.matchMedia('(pointer: coarse)').matches");
    $page->script(($this->ebkCaret)(1));
    cmWaitUntil($page, "document.querySelector('[data-test=\"block-handle\"][data-source=\"selection\"]') !== null");

    $sizes = cmScriptArray($page, "(() => { const handle = document.querySelector('[data-test=\"block-handle\"][data-source=\"selection\"]'); return { grip: handle.querySelector('[data-block-grip]').getBoundingClientRect().height, add: handle.querySelector('[aria-label=\"Add a block below\"]').getBoundingClientRect().height, name: handle.querySelector('[data-block-grip]').getAttribute('aria-label') }; })()");

    expect($sizes['grip'])->toBeGreaterThanOrEqual(44)
        ->and($sizes['add'])->toBeGreaterThanOrEqual(44)
        ->and($sizes['name'])->toBe('Block menu: Paragraph');
    $page->assertScript("document.querySelector('[aria-label=\"Add a block here\"]')", null);
});

it('passes axe with the block menu open, light and dark', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1280, 900), $this->edit))
        // The editor folds the admin sidebar as it opens; axe reads its labels once they have faded.
        ->assertScript("[...document.querySelectorAll('[data-sidebar=\"group-label\"]')].flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length", 0);

    $page->script(($this->ebkCaret)(1));
    ($this->ebkOpenMenu)($page);
    cmWaitUntil($page, "document.getAnimations().every((animation) => animation.playState !== 'running')");

    expect(cmAxeViolations($page))->toBe([]);
})->with(['light', 'dark']);
