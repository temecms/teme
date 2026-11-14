<?php

declare(strict_types=1);

use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Queries\ImportedPageTree;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Tree\ImportedPages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    importFake();
    $this->source = importSource();
    $this->author = User::factory()->create()->assignRole('editor');
});

/**
 * The walk's rows as `object_id => [depth, rank]`.
 *
 * @param  list<array{object_id: string, teme_id: string, wp_parent: int, depth: int, rank: int}>  $rows
 *
 * @return array<string, array{0: int, 1: int}>
 */
function walkShape(array $rows): array
{
    $shape = [];

    foreach ($rows as $row) {
        $shape[$row['object_id']] = [$row['depth'], $row['rank']];
    }

    return $shape;
}

it('walks a site\'s pages top-down, each with its rank among its WordPress siblings', function (): void {
    ImportedPages::import($this->source, $this->author, [
        10 => ['Root', 'root', 0, 0],
        11 => ['B', 'b', 10, 2],
        12 => ['C', 'c', 10, 0],
        13 => ['A', 'a', 10, 0],
    ]);

    expect(walkShape(app(ImportedPageTree::class)->batch($this->source->id, 0, 0, 100)))->toBe([
        '10' => [1, 0], '11' => [2, 2], '12' => [2, 1], '13' => [2, 0],
    ]);
});

it('starts a branch at a page whose parent wasn\'t imported', function (): void {
    ImportedPages::import($this->source, $this->author, [20 => ['Orphan', 'orphan', 99, 0], 21 => ['Under', 'under', 20, 0]]);

    expect(walkShape(app(ImportedPageTree::class)->batch($this->source->id, 0, 0, 100)))->toBe(['20' => [1, 0], '21' => [2, 0]]);
});

it('never reaches pages caught in a WordPress loop', function (): void {
    ImportedPages::import($this->source, $this->author, [30 => ['One', 'one', 0, 0], 31 => ['Two', 'two', 0, 0], 32 => ['Three', 'three', 0, 0]]);
    DB::table('import_map')->where('object_id', '30')->update(['facts' => DB::raw("jsonb_set(facts, '{parent}', '31')")]);
    DB::table('import_map')->where('object_id', '31')->update(['facts' => DB::raw("jsonb_set(facts, '{parent}', '30')")]);

    expect(walkShape(app(ImportedPageTree::class)->batch($this->source->id, 0, 0, 100)))->toBe(['32' => [1, 0]]);
});

it('carries on from its cursor', function (): void {
    ImportedPages::import($this->source, $this->author, [
        40 => ['Top', 'top', 0, 0], 41 => ['Other', 'other', 0, 1], 42 => ['Under top', 'under-top', 40, 0],
    ]);
    $walk = app(ImportedPageTree::class);

    $first = $walk->batch($this->source->id, 0, 0, 2);
    $last = $first[array_key_last($first)];

    expect(array_column($first, 'object_id'))->toBe(['40', '41'])
        ->and(array_column($walk->batch($this->source->id, $last['depth'], (int) $last['object_id'], 2), 'object_id'))->toBe(['42']);
});

it('reads only the hierarchical types\' pages', function (): void {
    ImportedPages::import($this->source, $this->author, [50 => ['Page', 'page-one', 0, 0]]);
    $post = ContentImports::import(ContentImports::item($this->author));
    ImportMapEntry::query()->create([
        'source_id' => $this->source->id, 'object_type' => 'post', 'object_id' => '51', 'teme_type' => 'content', 'teme_id' => $post['contentId'],
        'status' => 'imported', 'outcome' => 'created', 'imported_at' => now(), 'facts' => ['parent' => 0, 'menu_order' => 0],
    ]);

    expect(array_column(app(ImportedPageTree::class)->batch($this->source->id, 0, 0, 100), 'object_id'))->toBe(['50']);
});

it('lists the notes 50 a page', function (): void {
    $pages = [];

    foreach (range(101, 160) as $id) {
        $pages[$id] = ["Page {$id}", "page-{$id}", 0, 0];
    }

    ImportedPages::import($this->source, $this->author, $pages);
    DB::table('import_map')->where('source_id', $this->source->id)->update(['facts' => DB::raw("facts || jsonb_build_object('parent_note', 'A note.')")]);
    $walk = app(ImportedPageTree::class);

    $first = $walk->notes($this->source->id);
    $rest = $walk->notes($this->source->id, $first[array_key_last($first)]['object_id']);

    expect($first)->toHaveCount(50)
        ->and($first[0])->toBe(['object_id' => '101', 'title' => 'Page 101', 'note' => 'A note.'])
        ->and(array_column($rest, 'object_id'))->toBe(array_map(strval(...), range(151, 160)));
});
