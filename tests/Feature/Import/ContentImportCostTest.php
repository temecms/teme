<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\UpdateImportedContentInput;
use App\Domain\Contents\Operations\UpdateImportedContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ContentImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
});

/**
 * The statements $work sends: all of them, those that name the path table,
 * and those that write to an item's own tables.
 *
 * @return array{all: int, paths: int, writes: int}
 */
function cicCount(Closure $work): array
{
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = (string) $query->sql;
    });
    $work();
    // A table's name with or without the grammar's quotes: SearchText writes raw SQL.
    $writes = array_filter($sql, fn (string $statement): bool => preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+"?(contents|content_revisions|paths|content_terms|media_usages|content_links)"?[\s(]/i', $statement) === 1);

    return [
        'all' => count($sql),
        'paths' => count(array_filter($sql, fn (string $statement): bool => str_contains($statement, '"paths"'))),
        'writes' => count($writes),
    ];
}

/**
 * A published post n, with an old address of WordPress's own and an old slug.
 */
function cicItem(User $author, int $n): void
{
    ContentImports::import(ContentImports::item($author, [
        'slug' => "story-{$n}", 'title' => "Story {$n}", 'wordpressPath' => "2019/05/04/story-{$n}", 'oldSlugs' => ["old-story-{$n}"],
    ]));
}

it('costs a published post the same statements after 40 others as before them, the path table under two-fifths (I-444)', function (): void {
    // One first, so a first-call memo isn't counted as the item's cost.
    cicItem($this->author, 0);
    $early = cicCount(fn () => cicItem($this->author, 1));

    foreach (range(2, 41) as $n) {
        cicItem($this->author, $n);
    }

    $late = cicCount(fn () => cicItem($this->author, 42));

    // One more than the early count for the database lock's prune, a lottery about 1 time in 30.
    expect($late['all'])->toBeLessThanOrEqual($early['all'] + 1)
        ->and($late['paths'])->toBe($early['paths'])
        ->and($late['paths'] / $late['all'])->toBeLessThan(0.4);
});

it('writes nothing for a post a later run finds unchanged', function (): void {
    $companion = importFake();
    $source = importSource();
    ContentImports::person($source->id, $this->author, 3);
    $items = array_map(fn (int $n): array => ContentImports::wpItem($n, ['slug' => "story-{$n}", 'title' => "Story {$n}", 'permalink' => "https://old.example/story-{$n}/"]), range(1, 10));
    ContentImports::run($companion, $source, $items);
    $this->travel(5)->seconds();

    $again = cicCount(fn () => ContentImports::run($companion, $source, $items));

    expect($again['writes'])->toBe(0);
});

it('writes an unchanged post that waited for media without a new revision', function (): void {
    $first = ContentImports::import(ContentImports::item($this->author));
    $content = Content::query()->findOrFail($first['contentId']);

    $again = cicCount(fn () => scElevated(fn () => app(UpdateImportedContent::class)->execute(
        new UpdateImportedContentInput($content->id, $first['written'], ContentImports::item($this->author), null),
    )));

    expect($again['writes'])->toBe(0);
});
