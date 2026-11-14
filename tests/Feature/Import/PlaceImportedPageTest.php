<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Operations\Input\PlaceImportedPageInput;
use App\Domain\Import\Operations\PlaceImportedPage;
use App\Domain\Import\PlacedPage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Tree\ImportedPages;
use Tests\Fixtures\Tree\PageTree;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    importFake();
    $this->source = importSource();
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->pages = ImportedPages::import($this->source, $this->editor, [
        10 => ['About', 'about', 0, 0],
        11 => ['Team', 'team', 10, 0],
        12 => ['Other', 'other', 0, 1],
    ]);
});

/**
 * Places the page, as the operator unless an actor is given.
 */
function placeImported(string $sourceId, int $wordpressId, int $rank = 0, ?User $actor = null): PlacedPage
{
    $run = fn (): PlacedPage => app(PlaceImportedPage::class)->execute(new PlaceImportedPageInput($sourceId, (string) $wordpressId, $rank, $actor === null ? null : (string) $actor->id));

    return $actor === null ? scElevated($run) : $run();
}

/**
 * The page's map row's facts.
 *
 * @return array<string, mixed>
 */
function placedFacts(string $sourceId, int $wordpressId): array
{
    return ImportMapEntry::query()->where('source_id', $sourceId)->where('object_type', 'post')->where('object_id', (string) $wordpressId)->sole()->facts;
}

/**
 * Sets the page's WordPress parent on its map row.
 */
function placedParent(string $sourceId, int $wordpressId, int $parent): void
{
    $entry = ImportMapEntry::query()->where('source_id', $sourceId)->where('object_type', 'post')->where('object_id', (string) $wordpressId)->sole();
    $entry->forceFill(['facts' => [...$entry->facts, 'parent' => $parent]])->save();
}

it('puts a page back under its WordPress parent, at its WordPress address, the flat one answering 301', function (): void {
    expect(addrCurrent('content', $this->pages[11]->id))->toBe('team');

    placeImported($this->source->id, 10);
    $placed = placeImported($this->source->id, 11);
    $response = addrGet('/team/');

    expect($placed->status)->toBe(PlacedPage::PLACED)
        ->and($this->pages[11]->refresh()->parent_id)->toBe($this->pages[10]->id)
        ->and(addrCurrent('content', $this->pages[11]->id))->toBe('about/team')
        ->and($response->getStatusCode())->toBe(301)
        ->and(addrLocation($response))->toBe('/about/team/');
});

it('records what it placed', function (): void {
    placeImported($this->source->id, 11, 3);

    expect(placedFacts($this->source->id, 11)['placed'])->toEqual(['parent_id' => $this->pages[10]->id, 'position' => 3])
        ->and(placeImported($this->source->id, 11, 3)->status)->toBe(PlacedPage::UNCHANGED);
});

it('places it again when WordPress moved it and Teme didn\'t', function (): void {
    placeImported($this->source->id, 11);
    placedParent($this->source->id, 11, 12);

    placeImported($this->source->id, 11);

    expect($this->pages[11]->refresh()->parent_id)->toBe($this->pages[12]->id)
        ->and(addrCurrent('content', $this->pages[11]->id))->toBe('other/team')
        ->and(placedFacts($this->source->id, 11)['placed'])->toEqual(['parent_id' => $this->pages[12]->id, 'position' => 0]);
});

it('leaves a page moved in Teme where it is, with a note', function (Closure $moved): void {
    $moved($this);

    $place = placeImported($this->source->id, 11);

    expect($place->status)->toBe(PlacedPage::LEFT)
        ->and($place->code)->toBe('page.moved-here')
        ->and(placedFacts($this->source->id, 11)['parent_note'])->toBe('“Team” was moved in Teme, so it stays where you put it.')
        ->and($this->pages[11]->refresh()->parent_id)->toBe($this->pages[12]->id);
})->with([
    'after it was placed' => [function ($test): void {
        placeImported($test->source->id, 11);
        PageTree::place($test->pages[11], $test->pages[12]);
    }],
    'never placed, given a parent here' => [fn ($test) => PageTree::place($test->pages[11], $test->pages[12])],
]);

it('leaves a page whose parent wasn\'t imported, is in the trash, or is another type, with a note', function (Closure $case, string $code, string $words): void {
    $case($this);

    $place = placeImported($this->source->id, 11);

    expect($place->status)->toBe(PlacedPage::LEFT)
        ->and($place->code)->toBe($code)
        ->and(placedFacts($this->source->id, 11)['parent_note'])->toBe($words)
        ->and($this->pages[11]->refresh()->parent_id)->toBeNull()
        ->and(addrCurrent('content', $this->pages[11]->id))->toBe('team');
})->with([
    'not imported' => [fn ($test) => placedParent($test->source->id, 11, 99), 'page.parent-missing', '“Team” sits under a page that wasn\'t imported, so it\'s at the top level here.'],
    'in the trash' => [fn ($test) => app(TrashContent::class)->execute(new ContentActionInput($test->pages[10]->id, (string) $test->editor->id)), 'page.parent-trashed', '“Team”\'s parent “About” is in the trash here, so it stays where it is.'],
    'another type' => [function ($test): void {
        $post = ContentImports::import(ContentImports::item($test->editor, ['title' => 'News']));
        ImportMapEntry::query()->create([
            'source_id' => $test->source->id, 'object_type' => 'post', 'object_id' => '15', 'teme_type' => 'content', 'teme_id' => $post['contentId'],
            'status' => 'imported', 'outcome' => 'created', 'imported_at' => now(), 'facts' => ['parent' => 0],
        ]);
        placedParent($test->source->id, 11, 15);
    }, 'page.parent-type', '“Team”\'s parent “News” is a different kind of item here, so it stays where it is.'],
]);

it('leaves a page whose nested address something else holds, with a note', function (): void {
    $post = addrPublished($this->editor, 'Holder');
    DB::table('paths')->where('path', 'about/team')->delete();
    DB::table('paths')->where('target_id', $post->id)->where('is_current', true)->update(['path' => 'about/team']);

    $place = placeImported($this->source->id, 11);

    expect($place->code)->toBe('page.address-taken')
        ->and($place->note)->toBe('“Team” stays at /team/: WordPress had it at /about/team/, which the post "Holder" uses here.')
        ->and($this->pages[11]->refresh()->parent_id)->toBeNull();
});

it('leaves a page past the eighth level at the top, with a note, and places its children under it', function (): void {
    $pages = [];

    foreach (range(1, 10) as $level) {
        $pages[200 + $level] = ["Level {$level}", "level-{$level}", $level === 1 ? 0 : 199 + $level, 0];
    }

    $items = ImportedPages::import($this->source, $this->editor, $pages);

    foreach (array_keys($pages) as $id) {
        $places[$id] = placeImported($this->source->id, $id);
    }

    expect($places[209]->code)->toBe('page.too-deep')
        ->and($places[209]->note)->toBe('“Level 9” is more than eight levels down, so it\'s at the top level here, with its own pages under it.')
        ->and($items[209]->refresh()->parent_id)->toBeNull()
        ->and($items[210]->refresh()->parent_id)->toBe($items[209]->id)
        ->and($items[208]->refresh()->parent_id)->toBe($items[207]->id);
});

it('notes a page whose slug the import suffixed', function (): void {
    DB::table('contents')->where('id', $this->pages[11]->id)->update(['slug' => 'team-2']);

    $place = placeImported($this->source->id, 11);

    expect($place->status)->toBe(PlacedPage::PLACED)
        ->and($place->code)->toBe('page.slug-suffixed')
        ->and($place->note)->toBe('“Team” is at /about/team-2/: another page had the name team when it was imported.');
});

it('moves the pages a person put under it with it', function (): void {
    $extra = addrPublished($this->editor, 'Extra', 'page');
    PageTree::place($extra, $this->pages[11]);

    expect(addrCurrent('content', $extra->id))->toBe('team/extra');

    placeImported($this->source->id, 11);

    expect(addrCurrent('content', $extra->id))->toBe('about/team/extra');
});

it('refuses someone who may not import, and writes nothing', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $before = [DB::table('contents')->orderBy('id')->get(['id', 'parent_id', 'position'])->all(), DB::table('import_map')->orderBy('id')->pluck('facts')->all(), DB::table('paths')->orderBy('path')->get()->all()];

    expect(fn () => placeImported($this->source->id, 11, 0, $author))->toThrow(AuthorizationException::class);

    expect([DB::table('contents')->orderBy('id')->get(['id', 'parent_id', 'position'])->all(), DB::table('import_map')->orderBy('id')->pluck('facts')->all(), DB::table('paths')->orderBy('path')->get()->all()])->toEqual($before);
});
