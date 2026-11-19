<?php

declare(strict_types=1);

use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// The Compare versions screen as the design draws it: the crumbs and the way
// back above the title, the versions listed beside the comparison, a summary
// with its key, each changed block as a Before and After pair named by its
// type, and the blocks that did not change folded away.

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');

    // Three versions: created empty, a first text, then one paragraph changed, one added and the excerpt written.
    $draft = lifecycleDraft($this->editor, 'Harbour news');
    $this->created = (string) $draft->working_revision_id;
    $this->first = (string) docSave($draft, $this->editor, docWith(
        docText('The ferry leaves the harbour at nine.'),
        docText('Tickets are hot today.'),
        docText('Fares rise on Monday.'),
    ))->working_revision_id;
    $this->second = (string) docSave($draft, $this->editor, docWith(
        docText('The ferry leaves the harbour at nine.'),
        docText('Tickets are four pounds today.'),
        docText('Fares rise on Monday.'),
        docText('Bring a coat.'),
    ), fields: [...(array) ContentRevision::query()->findOrFail($this->first)->fields, BuiltInFields::EXCERPT => 'A short note.'])->working_revision_id;
    $this->draft = $draft;
    $this->compare = "/admin/content/{$draft->id}/compare?from={$this->first}&to={$this->second}";
});

it('names where it is in the crumbs and goes back to the editor from above the title', function (): void {
    $page = cmNavigate(cmSignIn($this->editor), $this->compare);
    cmWaitUntil($page, "document.querySelector('h1') !== null");

    $page->assertScript("document.querySelector('[data-test=\"crumbs\"]').innerText.replace(/\\s+/g, ' ').includes('Content')")
        ->assertScript("document.querySelector('[data-test=\"crumbs\"]').innerText.includes('Posts')")
        ->assertScript("document.querySelector('[data-test=\"crumbs\"]').innerText.includes('Compare versions')");

    // The way back sits above the title, in the page, not in a bar of its own.
    $page->assertScript(<<<JS
        (() => {
            const back = Array.from(document.querySelectorAll('a')).find((link) => link.innerText.trim() === 'Back to the editor');
            return back !== undefined
                && back.getAttribute('href').endsWith('/admin/content/{$this->draft->id}/edit')
                && (back.compareDocumentPosition(document.querySelector('h1')) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0
                && document.querySelector('[data-slot="editor-shell"]') === null;
        })()
        JS);
});

it('lists the saved versions beside the comparison, the two chosen tinted, and compares another from its Before button', function (): void {
    $page = cmNavigate(cmSignIn($this->editor), $this->compare);
    cmWaitUntil($page, "document.querySelector('[aria-label=\"Versions\"] li') !== null");

    $page->assertScript("document.querySelector('[aria-label=\"Versions\"]').innerText.includes('3 saved')")
        ->assertScript("document.querySelectorAll('[aria-label=\"Versions\"] li').length === 3")
        ->assertScript("Array.from(document.querySelectorAll('[aria-label=\"Versions\"] li')).at(-1).innerText.includes('Eze Editor · Created')")
        ->assertScript("document.querySelectorAll('[aria-label=\"Versions\"] li[data-chosen]').length === 2")
        ->assertScript("document.querySelectorAll('[aria-label=\"Versions\"] button[aria-pressed=\"true\"]').length === 2");

    // The oldest version's Before: the comparison reloads from it.
    $page->script("Array.from(document.querySelectorAll('[aria-label=\"Versions\"] li')).at(-1).querySelector('button').click()");
    cmWaitUntil($page, "location.search.includes('from={$this->created}') && location.search.includes('to={$this->second}')");
    cmWaitUntil($page, "Array.from(document.querySelectorAll('[aria-label=\"Versions\"] li')).at(-1).hasAttribute('data-chosen')");
});

it('sums up the changes with a key, and shows each changed block as a Before and After pair named by its type', function (): void {
    $page = cmNavigate(cmSignIn($this->editor), $this->compare);
    cmWaitUntil($page, "document.querySelector('[aria-label=\"Changes\"]') !== null");

    $page->assertScript("document.querySelector('[aria-label=\"Changes\"]').innerText.includes('2 blocks changed · 5 words added, 1 word removed')")
        ->assertScript("Array.from(document.querySelectorAll('[aria-label=\"Key\"] ins, [aria-label=\"Key\"] del')).map((mark) => mark.innerText).join(' ') === 'Added Removed'")
        ->assertScript("document.querySelector('[aria-label=\"Changes\"] h2').innerText === 'Title, excerpt and terms'");

    // The changed paragraph: its type in capitals on both sides, the words marked on each.
    $page->assertScript(<<<'JS'
        (() => {
            const row = document.querySelector('[data-kind="changed"]');
            const [before, after] = row.querySelectorAll('[data-side]');
            return before.innerText.startsWith('Before') && after.innerText.startsWith('After')
                && before.innerText.includes('PARAGRAPH') && after.innerText.includes('PARAGRAPH')
                && before.querySelector('del').innerText === 'hot' && before.querySelector('ins') === null
                && after.querySelector('ins').innerText === 'four pounds' && after.querySelector('del') === null;
        })()
        JS)
        ->assertScript("document.querySelector('[data-kind=\"added\"] [data-side=\"after\"]').innerText.includes('PARAGRAPH · ADDED')");

    // The two that did not change are folded away until asked for.
    $page->assertScript("document.querySelectorAll('[data-kind=\"same\"]').length === 0");
    $page->click('Show 2 blocks that did not change');
    cmWaitUntil($page, "document.querySelectorAll('[data-kind=\"same\"]').length === 2");
    $page->assertSee('Hide 2 blocks that did not change');
});

it('keeps a struck word apart from its replacement where before and after are drawn in one', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(400, 900), $this->compare);
    cmWaitUntil($page, "document.querySelector('[data-kind=\"changed\"] [data-inline]') !== null");

    $page->assertScript("document.querySelector('[data-kind=\"changed\"] [data-inline]').innerText.includes('Tickets are hot four pounds today.')");
});
