<?php

declare(strict_types=1);

use App\Domain\Cards\DocumentSchema;
use App\Domain\Contents\Models\ContentAutosave;
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
    $this->callout = '01K5Z9P2QH7N3X4C8V6B0M1T3D';
    // Saved as an article saved before this part was: its paragraphs carry no id; its card carries the id it was made with.
    $this->item = lifecycleDraft($this->author, 'Harbour news');
    docSave($this->item, $this->author, docWith(
        docText('The ferry is late.'),
        docText('Fares rise on Monday.'),
        ['type' => 'callout', 'attrs' => ['id' => $this->callout, 'tone' => 'note', 'text' => 'Gates open at six.']],
        docText('See you at the quay.'),
    ));
    $this->item->refresh();
    $this->edit = '/admin/content/'.$this->item->id.'/edit';

    // Warm the editor before the browser's first visit (I-638).
    $this->actingAs($this->author)->get($this->edit)->assertOk();

    // Each top-level paragraph's words and data-id, as the editor draws them.
    $this->paragraphs = fn (mixed $page): array => cmScriptArray($page, "Array.from(document.querySelectorAll('.teme-prose > p'), (block) => [block.textContent, block.getAttribute('data-id')])");

    // When the editor last saved, as the save state shows it.
    $this->savedAt = fn (mixed $page): mixed => $page->script("document.querySelector('[data-test=\"save-state\"]').dataset.savedAt ?? null");

    // Waits until an autosave newer than $before has landed.
    $this->savedSince = fn (mixed $page, mixed $before): mixed => cmWaitUntil($page, "(() => { const state = document.querySelector('[data-test=\"save-state\"]'); return state.dataset.state === 'saved' && (state.dataset.savedAt ?? null) !== ".json_encode($before, JSON_THROW_ON_ERROR).'; })()');

    // The top-level nodes' ids in this person's autosave.
    $this->autosaved = fn (): array => array_map(
        fn (array $node): mixed => $node['attrs']['id'] ?? null,
        (array) (ContentAutosave::query()->where('content_id', $this->item->id)->value('document')['content'] ?? []),
    );

    // Puts the caret at a character of a top-level block, counting its text only.
    $this->caret = fn (int $block, int $offset): string => <<<JS
        (() => {
            const body = document.querySelector('.teme-prose');
            body.focus();
            const walker = document.createTreeWalker(body.children[{$block}], NodeFilter.SHOW_TEXT);
            let node = walker.nextNode();
            let left = {$offset};
            while (node !== null && left > node.length) {
                left -= node.length;
                node = walker.nextNode();
            }
            const range = document.createRange();
            range.setStart(node, left);
            range.collapse(true);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            return true;
        })()
        JS;

    // Pastes this HTML where the caret is, as a clipboard carries it.
    $this->paste = fn (string $html): string => '(() => { const body = document.querySelector(".teme-prose"); const data = new DataTransfer(); data.setData("text/html", '.json_encode($html, JSON_THROW_ON_ERROR).'); body.dispatchEvent(new ClipboardEvent("paste", { clipboardData: data, bubbles: true, cancelable: true })); return true; })()';

    $this->wait = fn (mixed $page, int $milliseconds): mixed => $page->script("async () => { await new Promise((resolve) => setTimeout(resolve, {$milliseconds})); return true; }");
});

it('gives every block an id when an old article opens, and saves nothing until something changes (I-3362)', function (): void {
    $working = $this->item->working_revision_id;
    $revisions = ContentRevision::query()->where('content_id', $this->item->id)->count();

    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))->assertSee('Fares rise on Monday.');
    $before = ($this->savedAt)($page);
    $ids = array_column(($this->paragraphs)($page), 1);

    // Longer than the autosave's two seconds of quiet; then away, which saves anything unsaved.
    ($this->wait)($page, 3000);
    $after = ($this->savedAt)($page);
    cmNavigate($page, '/admin');
    ($this->wait)($page, 1000);

    expect($ids)->toHaveCount(3)
        ->and(bieBrowserMalformed($ids))->toBe([])
        ->and(array_unique($ids))->toHaveCount(3)
        ->and($after)->toBe($before)
        ->and(ContentAutosave::query()->where('content_id', $this->item->id)->exists())->toBeFalse()
        ->and($this->item->refresh()->working_revision_id)->toBe($working)
        ->and(ContentRevision::query()->where('content_id', $this->item->id)->count())->toBe($revisions);
});

it('splits a paragraph: the first half keeps its id, the second gets a new one, and the first change saves every id', function (): void {
    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))->assertSee('The ferry is late.');
    [$first, $second, $last] = array_column(($this->paragraphs)($page), 1);
    $before = ($this->savedAt)($page);

    // "The ferry| is late."
    $page->script(($this->caret)(0, 9));
    $page->keys('.teme-prose', 'Enter');
    cmWaitUntil($page, "document.querySelectorAll('.teme-prose > p').length === 4");
    ($this->savedSince)($page, $before);
    $now = ($this->paragraphs)($page);

    expect(array_column($now, 0))->toBe(['The ferry', ' is late.', 'Fares rise on Monday.', 'See you at the quay.'])
        ->and($now[0][1])->toBe($first)
        ->and($now[1][1])->not->toBeIn([$first, $second, $last])->toMatch(DocumentSchema::NODE_ID)
        ->and($now[2][1])->toBe($second)
        ->and($now[3][1])->toBe($last)
        ->and(($this->autosaved)())->toBe([$first, $now[1][1], $second, $this->callout, $last]);
});

it('gives a pasted block or card a new id when the article holds its id, and keeps one it doesn\'t hold (I-3362)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))->assertSee('See you at the quay.');
    [$first] = array_column(($this->paragraphs)($page), 1);
    $elsewhere = '01K5Z9P2QH7N3X4C8V6B0M1T3E';
    $before = ($this->savedAt)($page);

    // As the editor's own clipboard carries a copy: a paragraph of this article, one from another article, and this article's card.
    // The fourth top-level block (the card is the third), after its 20 characters.
    $page->script(($this->caret)(3, 20));
    $page->script(($this->paste)(
        '<p data-pm-slice="0 0 []" data-id="'.$first.'">A copy</p>'
        .'<p data-id="'.$elsewhere.'">From another article</p>'
        .'<div data-card="callout" id="'.$this->callout.'" tone="note" text="Gates open at six."></div>'
    ));
    cmWaitUntil($page, "document.querySelector('.teme-prose').innerText.includes('From another article')");
    ($this->savedSince)($page, $before);

    $byText = array_column(($this->paragraphs)($page), 1, 0);
    $saved = (array) ContentAutosave::query()->where('content_id', $this->item->id)->value('document');
    $callouts = array_values(array_filter($saved['content'], fn (array $node): bool => $node['type'] === 'callout'));
    $ids = array_values(array_filter(($this->autosaved)()));

    expect($byText['The ferry is late.'])->toBe($first)
        ->and($byText['A copy'])->not->toBe($first)->toMatch(DocumentSchema::NODE_ID)
        ->and($byText['From another article'])->toBe($elsewhere)
        ->and($callouts)->toHaveCount(2)
        ->and($callouts[0]['attrs']['id'])->toBe($this->callout)
        ->and($callouts[1]['attrs']['id'])->not->toBe($this->callout)->toMatch(DocumentSchema::NODE_ID)
        ->and($callouts[1]['attrs'])->toMatchArray(['tone' => 'note', 'text' => 'Gates open at six.'])
        ->and(array_unique($ids))->toHaveCount(count($ids));
});

it('gives a pasted card a new id when it travels as the editor now copies it and the article holds its id, and keeps its values (I-3362)', function (): void {
    $page = cmEditorSettled(cmSignIn($this->author)->navigate($this->edit))->assertSee('See you at the quay.');
    $before = ($this->savedAt)($page);
    $copied = htmlspecialchars(json_encode(['id' => $this->callout, 'tone' => 'note', 'text' => 'Gates open at six.'], JSON_THROW_ON_ERROR), ENT_QUOTES);

    // As the editor's own clipboard now carries this article's card: its values as JSON in data-attrs, its id among them.
    $page->script(($this->caret)(3, 20));
    $page->script(($this->paste)(
        '<div data-pm-slice="0 0 []" data-card="callout" data-attrs="'.$copied.'"></div>'
        .'<p>After the copy</p>'
    ));
    cmWaitUntil($page, "document.querySelector('.teme-prose').innerText.includes('After the copy')");
    ($this->savedSince)($page, $before);

    $saved = (array) ContentAutosave::query()->where('content_id', $this->item->id)->value('document');
    $callouts = array_values(array_filter($saved['content'], fn (array $node): bool => $node['type'] === 'callout'));

    expect($callouts)->toHaveCount(2)
        ->and($callouts[0]['attrs']['id'])->toBe($this->callout)
        ->and($callouts[1]['attrs']['id'])->not->toBe($this->callout)->toMatch(DocumentSchema::NODE_ID)
        ->and($callouts[1]['attrs'])->toMatchArray(['tone' => 'note', 'text' => 'Gates open at six.']);
});

/**
 * The ids among these that are not of a node ID's shape.
 *
 * @param  list<mixed>  $ids
 *
 * @return list<mixed>
 */
function bieBrowserMalformed(array $ids): array
{
    return array_values(array_filter($ids, fn (mixed $id): bool => ! is_string($id) || preg_match(DocumentSchema::NODE_ID, $id) !== 1));
}
