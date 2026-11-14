<?php

declare(strict_types=1);

use App\Domain\Ai\Models\AiActivity;
use App\Domain\Contents\Models\ContentRevision;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // The warm-up below signs in through actingAs(), and the browser must not inherit it.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->author = User::factory()->create(['name' => 'Ada Author'])->assignRole('author');
    $this->item = aiDraft($this->author, 'The ferry is late.', 'Fares rise on Monday.');
    $this->fake = rewriteReady();
    $this->edit = '/admin/content/'.$this->item->id.'/edit';

    // Warm the editor before the browser's first visit (I-638).
    $this->actingAs($this->author)->get($this->edit)->assertOk();

    // Selects text as a drag would: from a character in one top-level block to one in another, counting text only.
    $this->select = fn (int $fromBlock, int $from, int $toBlock, int $to): string => <<<JS
        (() => {
            const blocks = document.querySelectorAll('.teme-prose > *');
            const point = (block, offset) => {
                const walker = document.createTreeWalker(blocks[block], NodeFilter.SHOW_TEXT);
                let node = walker.nextNode();
                while (node !== null && offset > node.length) {
                    offset -= node.length;
                    node = walker.nextNode();
                }
                return [node, offset];
            };
            const range = document.createRange();
            range.setStart(...point({$fromBlock}, {$from}));
            range.setEnd(...point({$toBlock}, {$to}));
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        })()
        JS;

    // Focuses the writing surface, selects, and opens the Rewrite menu; click() and hover() wait for the button, then the menu's first line.
    $this->open = function (mixed $page, array $selection): void {
        $page->script("document.querySelector('.teme-prose').focus()");
        $page->script(($this->select)(...$selection));
        $page->click('[data-test="rewrite-menu"]')->hover('[data-test="rewrite-scope"]');

        // The menu has stopped opening; shellSettled() looks once (issue #83).
        cmWaitUntil($page, "(() => { const found = [...document.querySelectorAll('[role=\"menu\"]')]; return found.length > 0 && found.every((element) => element.getAnimations().length === 0); })()");
    };

    // Waits until the page's visible text has a sentence, or an element's does; the plugin's assertSee() looks once.
    $this->textShown = fn (mixed $page, string $text, string $within = 'body'): mixed => cmWaitUntil($page, '(document.querySelector('.json_encode($within, JSON_THROW_ON_ERROR).")?.innerText ?? '').includes(".json_encode($text, JSON_THROW_ON_ERROR).')');

    // The menu's first line and its presets, as the page has them.
    $this->menu = fn (mixed $page): array => cmScriptArray($page, "(() => { const menu = document.querySelector('[role=\"menu\"]'); return { first: menu.querySelector('[data-test=\"rewrite-scope\"]').textContent.trim(), presets: Array.from(menu.querySelectorAll('[role=\"menuitem\"]'), (item) => [item.textContent.trim(), item.getAttribute('aria-disabled') === 'true']) }; })()");
});

it('offers the seven presets for text within one paragraph, asks with the one chosen, and passes axe once the menu has closed (I-1309)', function (string $scheme): void {
    lscrPrefer($this->author, ['appearance.mode' => $scheme]);
    $this->fake->answer(aiText('boat'));

    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))->assertSee('The ferry is late.');
    ($this->open)($page, [0, 4, 0, 9]);

    expect(($this->menu)($page))->toBe([
        'first' => 'Rewrite the selected text',
        'presets' => [['Improve', false], ['Shorter', false], ['Longer', false], ['Simpler', false], ['Fix spelling and grammar', false], ['More formal', false], ['More casual', false]],
    ]);

    // Drawn in place: the selection menu is still on the page, holding the open menu.
    expect($page->script("document.querySelector('[role=\"toolbar\"][aria-label=\"Selection\"] [role=\"menu\"]') !== null"))->toBeTrue();

    // hover() waits for the proposal's Use: the answer has come.
    $page->click('[data-test="rewrite-shorter"]')
        ->hover('[data-test="ai-use"]');

    cmWaitUntil($page, "document.querySelector('[role=\"menu\"]') === null");

    $page->assertSeeIn('[aria-labelledby="assistant-request"]', 'Sent to Fake AI')
        ->assertSee('Rewrite · Shorter: the selected text')
        ->assertSeeIn('[aria-labelledby="assistant-request"] del', 'ferry')
        ->assertSeeIn('[aria-labelledby="assistant-request"] ins', 'boat')
        ->assertSee('To rewrite text, select it within one paragraph and choose Rewrite in the menu that appears.')
        ->assertNoAccessibilityIssues(3);

    expect(AiActivity::query()->sole()->variant)->toBe('shorter');
})->with(['light', 'dark']);

it('uses the rewrite as one change that Undo takes back, recorded under its preset (I-1309, I-317)', function (): void {
    $this->fake->answer(aiText('Fares go up'));

    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))->assertSee('Fares rise on Monday.');
    ($this->open)($page, [1, 0, 1, 13]);

    $page->click('[data-test="rewrite-simpler"]')
        ->click('[data-test="ai-use"]');

    ($this->textShown)($page, 'Suggestion used as one change.');
    ($this->textShown)($page, 'Fares go up Monday.', '.teme-prose');

    $page->assertSeeIn('.teme-prose', 'The ferry is late.');

    $revision = ContentRevision::query()->findOrFail($this->item->refresh()->working_revision_id);

    expect($revision->only(['kind', 'origin']))->toBe(['kind' => 'ai', 'origin' => 'ai'])
        ->and(AiActivity::query()->sole()->only(['variant', 'revision_id']))->toBe(['variant' => 'simpler', 'revision_id' => $revision->id]);

    $page->click('Undo');

    ($this->textShown)($page, 'Fares rise on Monday.', '.teme-prose');
});

it('leaves the spaces around a selection out of the rewrite, so the words beside it stay apart', function (): void {
    $this->fake->answer(aiText('boat'));

    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))->assertSee('The ferry is late.');
    // " ferry ", a space either side, as a double-click or a drag often takes it.
    ($this->open)($page, [0, 3, 0, 10]);

    $page->click('[data-test="rewrite-improve"]')
        ->hover('[data-test="ai-use"]')
        ->assertSeeIn('[aria-labelledby="assistant-request"] del', 'ferry')
        ->click('[data-test="ai-use"]');

    ($this->textShown)($page, 'Suggestion used as one change.');
    ($this->textShown)($page, 'The boat is late.', '.teme-prose');

    expect($this->fake->requests[0]->messages[1]->text)->toContain('<input name="selection">ferry</input>');
});

it('says to select within one paragraph when the selection runs into the next, and offers no preset (I-1305)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))->assertSee('The ferry is late.');
    ($this->open)($page, [0, 4, 1, 5]);

    $menu = ($this->menu)($page);

    expect($menu['first'])->toBe('Select text within one paragraph')
        ->and(array_column($menu['presets'], 1))->toBe(array_fill(0, 7, true))
        ->and($this->fake->requests)->toBe([]);
});

it('says to leave out a line break, which a one-line rewrite would lose', function (): void {
    $item = aiDraft($this->author, 'Intro.');
    docSave($item, $this->author, docWith(['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Harbour Road'],
        ['type' => 'hardBreak'],
        ['type' => 'text', 'text' => 'Portree'],
    ]]));
    $edit = '/admin/content/'.$item->id.'/edit';
    $this->actingAs($this->author)->get($edit)->assertOk();

    $page = cmEditorSettled(cmSignIn($this->author)->navigate($edit))->assertSee('Harbour Road');
    // From "Road" across the break into "Portree".
    ($this->open)($page, [0, 8, 0, 15]);

    $menu = ($this->menu)($page);

    expect($menu['first'])->toBe('Select text without a line break')
        ->and(array_column($menu['presets'], 1))->toBe(array_fill(0, 7, true));
});

it('shows no Rewrite button until the rewrite task has a model (I-321)', function (): void {
    aiChoose('rewrite', '');

    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))->assertSee('The ferry is late.');
    $page->script("document.querySelector('.teme-prose').focus()");
    $page->script(($this->select)(0, 4, 0, 9));

    // The selection menu is up (hover waits for it), with its marks and no Rewrite.
    $page->hover('[aria-label="Selection"]')
        ->assertPresent('[aria-label="Selection"] [aria-label="Bold"]')
        ->assertNotPresent('[data-test="rewrite-menu"]');
});
