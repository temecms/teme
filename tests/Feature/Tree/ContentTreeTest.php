<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Tree\ContentTree;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->tree = app(ContentTree::class);
});

/**
 * Writes the item's parent straight to the row, for a test about reading the tree.
 */
function treeUnder(Content $item, ?Content $parent, int $position = 0): Content
{
    DB::table('contents')->where('id', $item->id)->update(['parent_id' => $parent?->id, 'position' => $position]);

    return $item->refresh();
}

it('lists an item\'s descendants, every state, depth first then by ID, each saying whether it has an address', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    $team = treeUnder(addrPublished($this->editor, 'Team', 'page'), $about);
    $draft = treeUnder(lifecycleDraft($this->editor, 'Plans', 'page'), $about);
    $people = treeUnder(addrPublished($this->editor, 'People', 'page'), $team);
    $trashed = treeUnder(addrPublished($this->editor, 'Old', 'page'), $team);
    app(TrashContent::class)->execute(new ContentActionInput($trashed->id, (string) $this->editor->id));

    $rows = $this->tree->descendants($about->id);
    $firstLevel = collect([$team->id, $draft->id])->sort()->values()->all();
    $secondLevel = collect([$people->id, $trashed->id])->sort()->values()->all();

    expect(array_map(fn (object $row): string => $row->id, $rows))->toBe([...$firstLevel, ...$secondLevel])
        ->and(collect($rows)->mapWithKeys(fn (object $row): array => [$row->id => [$row->parent_id, $row->slug, $row->depth, $row->shown]])->all())->toEqual([
            $team->id => [$about->id, 'team', 1, true],
            $draft->id => [$about->id, 'plans', 1, false],
            $people->id => [$team->id, 'people', 2, true],
            $trashed->id => [$team->id, 'old', 2, false],
        ]);
});

it('gives an item and its ancestors, bottom first', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    $team = treeUnder(lifecycleDraft($this->editor, 'Team', 'page'), $about);
    $people = treeUnder(addrPublished($this->editor, 'People', 'page'), $team);

    expect($this->tree->ancestorIds($people->id))->toBe([$people->id, $team->id, $about->id])
        ->and($this->tree->ancestorIds($about->id))->toBe([$about->id]);
});

it('counts levels and height', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    $team = treeUnder(addrPublished($this->editor, 'Team', 'page'), $about);
    $people = treeUnder(addrPublished($this->editor, 'People', 'page'), $team);

    expect([$this->tree->levels($about->id), $this->tree->levels($team->id), $this->tree->levels($people->id)])->toBe([1, 2, 3])
        ->and([$this->tree->height($about->id), $this->tree->height($team->id), $this->tree->height($people->id)])->toBe([3, 2, 1]);
});

it('says whether an item has children, the trash included', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    $alone = addrPublished($this->editor, 'Alone', 'page');
    $old = treeUnder(addrPublished($this->editor, 'Old', 'page'), $about);
    app(TrashContent::class)->execute(new ContentActionInput($old->id, (string) $this->editor->id));

    expect($this->tree->hasChildren($about->id))->toBeTrue()
        ->and($this->tree->hasChildren($alone->id))->toBeFalse();
});

it('stops every walk at eight levels, even on a loop written straight to the table', function (): void {
    $one = lifecycleDraft($this->editor, 'One', 'page');
    $two = lifecycleDraft($this->editor, 'Two', 'page');
    DB::table('contents')->where('id', $one->id)->update(['parent_id' => $two->id]);
    DB::table('contents')->where('id', $two->id)->update(['parent_id' => $one->id]);

    expect(count($this->tree->descendants($one->id)))->toBe(ContentTree::MOST_LEVELS - 1)
        ->and(count($this->tree->ancestorIds($one->id)))->toBe(ContentTree::MOST_LEVELS + 1)
        ->and($this->tree->height($one->id))->toBe(ContentTree::MOST_LEVELS)
        ->and($this->tree->parentPath($one->id))->toBe('');
});

it('can serve a parent\'s children from contents_parent', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');

    foreach (range(1, 3) as $n) {
        addrPublished($this->editor, "Post {$n}");
        treeUnder(addrPublished($this->editor, "Child {$n}", 'page'), $about, $n);
    }

    DB::statement('ANALYZE contents');
    $plan = DB::transaction(function () use ($about): string {
        DB::statement('SET LOCAL enable_seqscan = off');

        return collect(DB::select('EXPLAIN SELECT id FROM contents WHERE parent_id = ? ORDER BY position, id', [$about->id]))
            ->map(fn (object $row): string => (string) $row->{'QUERY PLAN'})->implode("\n");
    });

    expect($plan)->toContain('contents_parent');
});
