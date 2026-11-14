<?php

declare(strict_types=1);

use App\Domain\Contents\AuthorSort;
use App\Domain\Users\Actors;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eve'])->assignRole('editor');
});

it('adds and removes terms across a selection without touching the terms it was not asked about', function (): void {
    $transport = taxonomyTerm('category', 'Transport');
    $roads = taxonomyTerm('category', 'Roads');
    $business = taxonomyTerm('category', 'Business');
    $one = typesDraft($this->editor, 'post', 'One', [$transport->id]);
    $two = typesDraft($this->editor, 'post', 'Two', [$transport->id, $roads->id]);

    lscrBulkEdit($this->editor, [$one, $two], ['add_terms' => [$business->id], 'remove_terms' => [$transport->id]])
        ->assertOk()
        ->assertJsonPath('done', 2)
        ->assertJsonPath('skipped', []);

    expect($one->fresh()->workingRevision->terms)->toBe([$business->id])
        ->and($two->fresh()->workingRevision->terms)->toBe([$roads->id, $business->id]);
});

it('changes the author and the status in the same edit', function (): void {
    $a = lifecycleDraft($this->author, 'A');
    $b = lifecycleDraft($this->author, 'B');

    lscrBulkEdit($this->editor, [$a, $b], ['author_id' => $this->editor->id, 'status' => 'published'])
        ->assertOk()
        ->assertJsonPath('done', 2)
        ->assertJsonPath('changed.0.summary', 'author changed; published');

    foreach ([$a, $b] as $item) {
        $item->refresh();

        expect((int) $item->author_id)->toBe($this->editor->id)
            ->and($item->status)->toBe('published')
            ->and($item->published_revision_id)->toBe($item->working_revision_id)
            ->and(DB::table('contents')->where('id', $item->id)->value('author_sort'))->toBe(AuthorSort::of('Eve'));
    }
});

it('says what it changed on each item, and skips a status the item already has', function (): void {
    $draft = lifecycleDraft($this->author, 'Still a draft');
    $tag = taxonomyTerm('tag', 'Harbour');
    $revisions = DB::table('content_revisions')->where('content_id', $draft->id)->count();

    lscrBulkEdit($this->editor, [$draft], ['add_terms' => [$tag->id], 'author_id' => $this->editor->id, 'status' => 'draft'])
        ->assertOk()
        ->assertJsonPath('done', 1)
        ->assertJsonPath('changed.0', ['id' => $draft->id, 'title' => 'Still a draft', 'summary' => 'terms and author changed; already a draft']);

    // One UpdateContentDetails for terms and author together; no UnpublishContent.
    expect(DB::table('content_revisions')->where('content_id', $draft->id)->count())->toBe($revisions + 1)
        ->and($draft->fresh()->status)->toBe('draft');
});

it('reports the items it could not change, one by one, and changes the rest', function (): void {
    $mine = lifecycleDraft($this->author, 'Mine');
    $theirs = lifecycleDraft($this->editor, 'Theirs');
    $tag = taxonomyTerm('tag', 'Harbour');

    lscrBulkEdit($this->author, [$mine, $theirs], ['add_terms' => [$tag->id]])
        ->assertOk()
        ->assertJsonPath('done', 1)
        ->assertJsonPath('changed', [['id' => $mine->id, 'title' => 'Mine', 'summary' => 'terms changed']])
        ->assertJsonPath('skipped', [['id' => $theirs->id, 'title' => 'Theirs', 'reason' => 'You may not change this item.']]);

    expect($mine->fresh()->workingRevision->terms)->toBe([$tag->id]);
});

it('writes nothing for an item the actor may not edit', function (): void {
    $theirs = lifecycleDraft($this->editor, 'Theirs');
    listsPublish($theirs, $this->editor);
    $before = $theirs->fresh();
    $revisions = DB::table('content_revisions')->where('content_id', $theirs->id)->count();
    $tag = taxonomyTerm('tag', 'Harbour');

    lscrBulkEdit($this->author, [$theirs], ['add_terms' => [$tag->id], 'author_id' => $this->author->id, 'status' => 'draft'])
        ->assertOk()
        ->assertJsonPath('done', 0)
        ->assertJsonPath('skipped.0.reason', 'You may not change this item.');

    $after = $theirs->fresh();

    expect($after->working_revision_id)->toBe($before->working_revision_id)
        ->and($after->published_revision_id)->toBe($before->published_revision_id)
        ->and($after->status)->toBe('published')
        ->and((int) $after->author_id)->toBe($this->editor->id)
        ->and(DB::table('content_revisions')->where('content_id', $theirs->id)->count())->toBe($revisions)
        ->and(DB::table('content_terms')->where('content_id', $theirs->id)->where('term_id', $tag->id)->count())->toBe(0);
});

it('takes a large selection as batches of 25, each reported, adding up to one report', function (): void {
    $items = collect(range(1, 60))->map(fn (int $n) => lifecycleDraft($this->editor, "Item {$n}"));
    $tag = taxonomyTerm('tag', 'Harbour');

    // The browser's batch size (BULK_EDIT_BATCH in bulk-edit-dialog.tsx).
    $reports = $items->chunk(25)->map(fn ($batch): array => lscrBulkEdit($this->editor, $batch->values()->all(), ['add_terms' => [$tag->id]])->assertOk()->json())->values();

    expect($reports)->toHaveCount(3)
        ->and($reports->pluck('done')->all())->toBe([25, 25, 10])
        ->and($reports->flatMap(fn (array $report): array => $report['changed'])->pluck('id')->sort()->values()->all())
        ->toBe($items->pluck('id')->sort()->values()->all())
        ->and($reports->flatMap(fn (array $report): array => $report['skipped'])->all())->toBe([])
        ->and(DB::table('content_terms')->where('term_id', $tag->id)->where('scope', 'working')->count())->toBe(60);
});

it('still refuses a request of more than 100 items', function (): void {
    $this->actingAs($this->editor)
        ->postJson(route('content.bulk', ['type' => 'posts']), [
            'action' => 'edit',
            'ids' => array_map(fn (): string => (string) Str::ulid(), range(1, 101)),
            'status' => 'keep',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids');
});

it('sends the category tree with the list, for the Category filter and Bulk Edit alike, and never a flat taxonomy\'s', function (): void {
    $transport = taxonomyTerm('category', 'Transport');
    taxonomyTerm('category', 'Roads', $transport->id);
    taxonomyTerm('tag', 'Harbour');

    $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('termTrees.category', 2)
            ->where('termTrees.category.0.parentId', $transport->id)
            ->missing('termTrees.tag'));
});

it('loads the person, with their roles and permissions, once a batch (B-201)', function (): void {
    $ferries = taxonomyTerm('tag', 'Ferries');
    $roleLoads = function (int $count) use ($ferries): int {
        $items = array_map(fn (int $n) => typesDraft($this->editor, 'post', "Item {$count}.{$n}"), range(1, $count));
        DB::flushQueryLog();
        DB::enableQueryLog();

        lscrBulkEdit($this->editor, $items, ['add_terms' => [$ferries->id]])->assertOk()->assertJsonPath('done', $count);

        return collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'inner join "model_has_roles"'))->count();
    };

    // The admin's middleware reads the person's roles for its own shared data
    // on every request (B-298), and a little more on the first; after one
    // request to warm it, the batch adds none for its items.
    $roleLoads(1);

    expect($roleLoads(3))->toBe($roleLoads(1));
});

it('loads the person once a batch on a first edit too, where each item is published at once (B-201)', function (): void {
    $ferries = taxonomyTerm('tag', 'Ferries');
    $roleLoads = function (int $count) use ($ferries): int {
        // Published with nothing waiting: the edit publishes at once, after
        // asking whether the person may publish.
        $items = array_map(fn (int $n) => addrPublished($this->editor, "Live {$count}.{$n}"), range(1, $count));
        DB::flushQueryLog();
        DB::enableQueryLog();

        lscrBulkEdit($this->editor, $items, ['add_terms' => [$ferries->id]])->assertOk()->assertJsonPath('done', $count);

        return collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'inner join "model_has_roles"'))->count();
    };

    $roleLoads(1);

    expect($roleLoads(3))->toBe($roleLoads(1));
});

it('keeps no one once the batch is done (B-201)', function (): void {
    $ferries = taxonomyTerm('tag', 'Ferries');

    lscrBulkEdit($this->editor, [typesDraft($this->editor, 'post', 'One')], ['add_terms' => [$ferries->id]])->assertOk();
    DB::enableQueryLog();

    app(Actors::class)->find((string) $this->editor->id);

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "users"')))->toHaveCount(1);
});
