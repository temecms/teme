<?php

declare(strict_types=1);

use App\Domain\Contents\Models\ContentAutosave;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// The link editor on the Combobox: something on this site found by its
// title and stored by its id, or an address typed in (rule 9).

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
    $this->festival = addrPublished($this->editor, 'Harbour festival');
    $this->item = docSave(lifecycleDraft($this->editor, 'Harbour news'), $this->editor, docWith(
        docText('The ferry timetable changes on Monday.'),
    ));
    $this->edit = "/admin/content/{$this->item->id}/edit";

    // Selects the word "timetable" in the first paragraph, as a double click would.
    $this->elkSelectWord = <<<'JS'
        (() => {
            const body = document.querySelector('.teme-prose');
            body.focus();
            const walker = document.createTreeWalker(body.children[0], NodeFilter.SHOW_TEXT);
            let text = walker.nextNode();
            while (text !== null && !text.data.includes('timetable')) {
                text = walker.nextNode();
            }
            const from = text.data.indexOf('timetable');
            const range = document.createRange();
            range.setStart(text, from);
            range.setEnd(text, from + 'timetable'.length);
            getSelection().removeAllRanges();
            getSelection().addRange(range);
            return true;
        })()
        JS;

    // The link editor's panel, and its Combobox's button.
    $this->elkPanel = '[data-test="link-editor"]';
    $this->elkTrigger = '[data-test="link-editor"] [data-slot="combobox-trigger"]';

    // The first paragraph's text runs in this person's autosave, with their marks.
    $this->elkSavedRuns = fn (): array => ContentAutosave::query()->where('content_id', $this->item->id)->value('document')['content'][0]['content'] ?? [];

    // The link on "timetable" in the autosave, or null.
    $this->elkSavedLink = function (): ?array {
        foreach (($this->elkSavedRuns)() as $run) {
            if (($run['text'] ?? '') === 'timetable') {
                foreach ($run['marks'] ?? [] as $mark) {
                    if ($mark['type'] === 'link') {
                        return $mark['attrs'];
                    }
                }
            }
        }

        return null;
    };

    // Waits in the browser until a save newer than $before has landed.
    $this->elkSavedSince = fn (mixed $page, mixed $before): mixed => cmWaitUntil($page, "(() => { const state = document.querySelector('[data-test=\"save-state\"]'); return state.dataset.state === 'saved' && (state.dataset.savedAt ?? null) !== ".json_encode($before, JSON_THROW_ON_ERROR).'; })()');
    $this->elkSavedAt = fn (mixed $page): mixed => $page->script("document.querySelector('[data-test=\"save-state\"]').dataset.savedAt ?? null");

    // Opens the link editor on "timetable" with Ctrl K, then its search with ↓, and types.
    $this->elkSearch = function (mixed $page, string $typed): void {
        $page->script($this->elkSelectWord);
        $page->keys('.teme-prose', 'Control+k');
        cmWaitUntil($page, "document.activeElement === document.querySelector('{$this->elkTrigger}')");
        $page->page()->locator(':focus')->press('ArrowDown');
        cmWaitUntil($page, "document.activeElement?.getAttribute('data-slot') === 'combobox-input'");
        $page->page()->locator(':focus')->type($typed);
    };

    // The options the Combobox shows, by their names.
    $this->elkOptions = "Array.from(document.querySelectorAll('[data-slot=\"combobox-item\"]'), (item) => item.querySelector('.truncate').textContent)";

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

it('links a word to an article found by keyboard alone, stored by its id', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    $before = ($this->elkSavedAt)($page);

    ($this->elkSearch)($page, 'festiv');
    cmWaitUntil($page, "{$this->elkOptions}.join() === 'Harbour festival'");
    $page->page()->locator(':focus')->press('ArrowDown');
    $page->page()->locator(':focus')->press('Enter');
    cmWaitUntil($page, "document.querySelector('{$this->elkTrigger}').textContent.includes('Harbour festival')");

    $page->click('[data-test="link-editor"] button:text-is("Apply")');
    cmWaitUntil($page, "document.querySelector('{$this->elkPanel}') === null && document.querySelector('.teme-prose a')?.textContent === 'timetable'");
    ($this->elkSavedSince)($page, $before);

    $link = ($this->elkSavedLink)();

    expect($link['href'] ?? null)->toBe("teme:content/{$this->festival->id}")
        ->and((string) ($link['href'] ?? ''))->not->toContain('http');
});

it('links to a pasted outside address from the first option', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    $before = ($this->elkSavedAt)($page);

    ($this->elkSearch)($page, 'example.org/tide');
    cmWaitUntil($page, "{$this->elkOptions}[0] === 'Link to “https://example.org/tide”'");
    $page->page()->locator(':focus')->press('ArrowDown');
    $page->page()->locator(':focus')->press('Enter');
    $page->click('[data-test="link-editor"] button:text-is("Apply")');
    cmWaitUntil($page, "document.querySelector('{$this->elkPanel}') === null");
    ($this->elkSavedSince)($page, $before);

    expect(($this->elkSavedLink)()['href'] ?? null)->toBe('https://example.org/tide');
});

it('turns this site\'s own address into the item\'s id', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));
    $before = ($this->elkSavedAt)($page);
    $address = $page->script('window.location.origin').'/'.ltrim((string) addrCurrent('content', $this->festival->id), '/');

    ($this->elkSearch)($page, $address);
    cmWaitUntil($page, "{$this->elkOptions}[0]?.startsWith('Link to “')");
    $page->page()->locator(':focus')->press('ArrowDown');
    $page->page()->locator(':focus')->press('Enter');
    $page->click('[data-test="link-editor"] button:text-is("Apply")');
    cmWaitUntil($page, "document.querySelector('{$this->elkPanel}') === null");
    ($this->elkSavedSince)($page, $before);

    expect(($this->elkSavedLink)()['href'] ?? null)->toBe("teme:content/{$this->festival->id}");
});

it('removes a link, and Esc leaves the text as it was', function (): void {
    $this->item = docSave($this->item, $this->editor, docWith(['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'The ferry '],
        ['type' => 'text', 'text' => 'timetable', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org/tide']]]],
        ['type' => 'text', 'text' => ' changes on Monday.'],
    ]]));
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), $this->edit));

    // Esc: the panel closes, the link stays, the caret is back in the text.
    $page->script($this->elkSelectWord);
    $page->keys('.teme-prose', 'Control+k');
    cmWaitUntil($page, "document.querySelector('{$this->elkPanel}') !== null");
    $page->page()->locator(':focus')->press('Escape');
    cmWaitUntil($page, "document.querySelector('{$this->elkPanel}') === null && document.activeElement === document.querySelector('.teme-prose')");
    $page->assertScript("document.querySelector('.teme-prose a')?.getAttribute('href')", 'https://example.org/tide');

    $before = ($this->elkSavedAt)($page);
    $page->script($this->elkSelectWord);
    $page->keys('.teme-prose', 'Control+k');
    cmWaitUntil($page, "document.querySelector('{$this->elkPanel}') !== null");
    $page->click('[data-test="link-editor"] button:text-is("Remove link")');
    cmWaitUntil($page, "document.querySelector('{$this->elkPanel}') === null && document.querySelector('.teme-prose a') === null");
    ($this->elkSavedSince)($page, $before);

    expect(($this->elkSavedLink)())->toBeNull();
});
