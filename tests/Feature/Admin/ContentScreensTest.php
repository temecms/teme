<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
});

it('shows the posts list with tabs and rows, and never a total', function (): void {
    lifecycleDraft($this->author, 'Hello');

    $this->actingAs($this->author)
        ->get('/admin/posts')
        ->assertInertia(fn (Assert $page) => $page
            ->component('content/index')
            ->where('type', 'posts')
            ->where('rows.0.title', 'Hello')
            ->where('tabs.0', ['key' => 'all', 'label' => 'All', 'count' => 1])
            ->missing('total')
            ->has('authors')
            ->where('can.changeAuthor', false));
});

it('starts a new draft from a title and opens it in the editor', function (): void {
    $response = $this->actingAs($this->editor)->post('/admin/pages', ['title' => 'About us']);

    $draft = Content::query()->where('type', 'page')->sole();

    $response->assertRedirect(route('content.edit', $draft->id));
    expect($draft->title)->toBe('About us');
});

it('no longer says the editor arrives in a later release', function (): void {
    expect(file_get_contents(app_path('Http/Admin/Contents/ContentListController.php')))
        ->not->toContain('The editor arrives in a later release');
});

it('leads from a row into the editor, with Quick edit and Preview beside Edit, the decisions next and Trash last', function (): void {
    $list = uiComponent('pages/content/index.tsx');

    // The editor link is built in the browser from the row's id, which the
    // list already sends (rows.N.id), so the source is what carries it.
    expect($list)->toContain('contentEdit(row.id)')
        ->toMatch("/id: 'edit',\s*owner: 'core',\s*label: __\('Edit'\),\s*href:/")
        // The line: Edit, Quick edit, then View or Preview (look) last; the menu: the decisions, then Trash last.
        ->toMatch("/id: 'edit'.*id: 'quick-edit'.*onSelect: quickEdit,\s*\},\s*look,\s*\]/s")
        ->toContain("id: 'preview'")
        ->toMatch("/id: 'approve'.*action\('publish', __\('Publish'\)\).*action\('unpublish', __\('Revert to draft'\)\).*action\('trash', __\('Trash'\), true\)/s")
        ->and(uiComponent('components/list/row-actions.tsx'))->toContain("'href' in item");
});

it('quick edits through UpdateContentDetails', function (): void {
    $content = lifecycleDraft($this->author, 'Old');

    $this->actingAs($this->author)
        ->patch("/admin/posts/{$content->id}/details", ['title' => 'New', 'expected_revision_id' => $content->working_revision_id])
        ->assertSessionHasNoErrors();

    expect($content->fresh()->title)->toBe('New');
});

it('explains a stale quick edit instead of overwriting', function (): void {
    $content = lifecycleDraft($this->author, 'Old');

    $this->actingAs($this->author)
        ->patch("/admin/posts/{$content->id}/details", ['title' => 'New', 'expected_revision_id' => 'stale'])
        ->assertSessionHasErrors('expected_revision_id');
});

it('trashes, restores, publishes and deletes for good from the row', function (): void {
    $content = lifecycleDraft($this->author, 'Row');

    $this->actingAs($this->author)->post("/admin/posts/{$content->id}/publish")->assertSessionHasNoErrors();
    expect($content->fresh()->status)->toBe('published');

    $this->actingAs($this->author)->post("/admin/posts/{$content->id}/trash")->assertSessionHasNoErrors();
    expect(Content::withTrashed()->find($content->id)->trashed())->toBeTrue();

    $this->actingAs($this->author)->post("/admin/posts/{$content->id}/restore")->assertSessionHasNoErrors();
    $this->actingAs($this->author)->post("/admin/posts/{$content->id}/trash");
    $this->actingAs($this->author)->delete("/admin/posts/{$content->id}")->assertSessionHasNoErrors();

    expect(Content::withTrashed()->find($content->id))->toBeNull();
});

it('runs a bulk action item by item and reports what it skipped', function (): void {
    $mine = lifecycleDraft($this->author, 'Mine');
    $alsoMine = lifecycleDraft($this->author, 'Also mine');
    $theirs = lifecycleDraft($this->editor, 'Theirs');

    $this->actingAs($this->author)
        ->post('/admin/posts/bulk', ['action' => 'trash', 'ids' => [$mine->id, $alsoMine->id, $theirs->id], 'titles' => [$theirs->id => 'Theirs']])
        ->assertSessionHasNoErrors();

    expect(Content::onlyTrashed()->count())->toBe(2)
        ->and(Content::find($theirs->id))->not->toBeNull()
        ->and(session('bulk'))->toBe([
            'done' => 2,
            'skipped' => [['id' => $theirs->id, 'title' => 'Theirs', 'reason' => 'You may not change this item.']],
        ]);
});

it('adds a tag to many items, each as its own revision', function (): void {
    $tag = taxonomyTerm('tag', 'Featured');
    $a = lifecycleDraft($this->author, 'A');
    $b = lifecycleDraft($this->author, 'B');

    $this->actingAs($this->author)
        ->post('/admin/posts/bulk', ['action' => 'add_term', 'term_id' => $tag->id, 'ids' => [$a->id, $b->id]])
        ->assertSessionHasNoErrors();

    expect($a->fresh()->workingRevision->terms)->toBe([$tag->id])
        ->and($b->fresh()->workingRevision->terms)->toBe([$tag->id]);
});

it('refuses a bulk request larger than one page', function (): void {
    $ids = array_map(fn (): string => (string) Str::ulid(), range(1, 101));

    $this->actingAs($this->author)->post('/admin/posts/bulk', ['action' => 'trash', 'ids' => $ids])->assertSessionHasErrors('ids');
});

it('returns 404 for an unknown content type', function (): void {
    $this->actingAs($this->author)->get('/admin/articles')->assertNotFound();
});

it('reads the empty author choice as every author', function (): void {
    lifecycleDraft($this->author, 'By Ada');
    lifecycleDraft($this->editor, 'By Eve');

    // The filter form is a GET form: "All authors" submits author= with no
    // value, never the select field's private sentinel.
    $this->actingAs($this->editor)
        ->get('/admin/posts?author=&search=')
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 2)
            ->where('filtered', false)
            ->missing('filters.author'));
});

it('starts an empty draft from Add new and opens it in the editor, one address each (I-425)', function (string $list, string $type, string $role): void {
    $actor = $this->{$role};
    $first = $this->actingAs($actor)->post($list);
    $second = $this->actingAs($actor)->post($list);

    $one = Content::query()->where('type', $type)->where('slug', 'untitled')->sole();
    $two = Content::query()->where('type', $type)->where('slug', 'untitled-2')->sole();

    $first->assertRedirect(route('content.edit', $one->id));
    $second->assertRedirect(route('content.edit', $two->id));

    expect([$one->title, $one->status, $two->title, $two->status])->toBe(['', 'draft', '', 'draft']);

    $this->actingAs($actor)->get(route('content.edit', $one->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('content/edit')->where('session.title', ''));
})->with([
    'a post' => ['/admin/posts', 'post', 'author'],
    'a page' => ['/admin/pages', 'page', 'editor'],
]);

it('shows an empty title as (no title), and quotes it in a sentence as Untitled', function (): void {
    $titles = [['Harbour festival'], [''], ['   '], [null]];

    expect(guardCall('lib/titles.ts', 'shownTitle', $titles))->toBe(['Harbour festival', '(no title)', '(no title)', '(no title)'])
        ->and(guardCall('lib/titles.ts', 'quotedTitle', $titles))->toBe(['Harbour festival', 'Untitled', 'Untitled', 'Untitled']);
});

it('opens a new draft from every door with one press', function (): void {
    expect(uiComponent('pages/content/index.tsx'))
        ->not->toContain('new-draft-title')
        ->not->toContain("get('new')")
        ->toContain("__('Add new :singular'")
        ->and(uiComponent('components/palette/palette-commands.ts'))
        ->toContain('router.post(')
        ->toContain('in the editor`')
        ->not->toContain("new: '1'")
        ->and(uiComponent('pages/dashboard.tsx'))->not->toContain("new: '1'")
        ->and(uiComponent('pages/content/edit.tsx'))
        ->toContain("session.title.trim() === ''")
        ->toContain('onSlug: setSlug')
        ->and(uiComponent('components/editor/use-autosave.ts'))->toContain('onSlug');
});

it('names an empty title wherever the admin shows an item\'s title, never a blank', function (string $file, string $helper): void {
    expect(uiComponent($file))
        ->toContain("from '@/lib/titles'")
        ->toContain("{$helper}(");
})->with([
    ['pages/content/index.tsx', 'shownTitle'],
    ['pages/content/edit.tsx', 'shownTitle'],
    ['components/dashboard/dashboard-panel.tsx', 'shownTitle'],
    ['components/palette/command-palette.tsx', 'shownTitle'],
    ['components/list/bulk-edit-dialog.tsx', 'shownTitle'],
    ['components/navigation/add-item-panel.tsx', 'shownTitle'],
    ['components/editor/cards/button-card.tsx', 'shownTitle'],
    ['components/editor/link-popover.tsx', 'shownTitle'],
    ['components/editor/rail/canonical-choice.tsx', 'shownTitle'],
    ['components/editor/rail/canonical-choice.tsx', 'quotedTitle'],
    ['components/editor/rail/search-panel.tsx', 'quotedTitle'],
    ['components/editor/rail/checks-panel.tsx', 'quotedTitle'],
    ['components/fields/home-page-setting.tsx', 'shownTitle'],
    ['components/comments/comment-details.tsx', 'shownTitle'],
    ['pages/comments/index.tsx', 'shownTitle'],
    ['components/media-drawer.tsx', 'shownTitle'],
    ['components/tools/redirect-form.tsx', 'shownTitle'],
    ['pages/tools/redirects.tsx', 'shownTitle'],
]);
