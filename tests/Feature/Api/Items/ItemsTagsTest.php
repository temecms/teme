<?php

declare(strict_types=1);

use App\Domain\Api\ApiQuery;
use App\Domain\Api\Items\ItemFilters;
use App\Domain\Api\Items\ItemQuery;
use App\Domain\Api\Items\ItemShapes;
use App\Domain\Api\Items\ItemsQuery;
use App\Domain\Api\Live;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Fixtures\Api\DeliverySite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
    $this->site = DeliverySite::build();
});

/**
 * The tags a list with this query string records, its summaries drawn.
 *
 * @return list<string>
 */
function itemsTagsOfList(string $query): array
{
    $record = app(RenderRecord::class);
    $record->start(0);
    $filters = ItemFilters::from(ApiQuery::from(Request::create('/api/v1/items?'.$query), ['type', 'term', 'author', 'published_since', 'published_before', 'sort', 'limit', 'after']));
    $page = app(ItemsQuery::class)->page($filters);
    $shapes = app(ItemShapes::class);
    $parts = $shapes->parts($page['rows']);
    array_map(fn (object $row): array => $shapes->summary($row, $parts), $page['rows']);

    return $record->tags();
}

it('tags a list with its types\' lists and each item, term, author and image it shows', function (): void {
    $site = $this->site;
    $tags = itemsTagsOfList('limit=100');

    expect($tags)->toContain(...array_map(PageTags::listOf(...), Live::types()))
        ->toContain(PageTags::content($site->public->id), PageTags::content($site->protected->id), PageTags::content($site->about->id))
        ->toContain(PageTags::term($site->genre->id))
        ->toContain(PageTags::person((string) $site->author->id))
        ->toContain(PageTags::media($site->shell->id))
        ->not->toContain(PageTags::media($site->protectedOnly->id))
        ->not->toContain(PageTags::content($site->private->id))
        ->and(itemsTagsOfList('type=movie'))->toContain(PageTags::listOf('movie'))->not->toContain(PageTags::listOf('page'));
});

it('tags a filtered list with its term\'s or author\'s list', function (): void {
    $site = $this->site;
    $slug = (string) DB::table('users')->where('id', $site->author->id)->value('slug');

    expect(itemsTagsOfList('term='.$site->genre->id))->toContain(PageTags::filedUnder('genre', $site->genre->id), PageTags::term($site->genre->id))
        ->and(itemsTagsOfList('author='.$slug))->toContain(PageTags::author((string) $site->author->id), PageTags::person((string) $site->author->id));
});

it('tags one item with everything it shows, its links and media included', function (): void {
    $site = $this->site;
    $record = app(RenderRecord::class);
    $record->start(0);
    $query = app(ItemQuery::class);
    $row = $query->byId($site->public->id);
    expect($row)->not->toBeNull();
    $query->full($row);

    expect($record->tags())->toContain(
        PageTags::content($site->public->id),
        PageTags::term($site->genre->id),
        PageTags::person((string) $site->author->id),
        PageTags::media($site->shell->id),
        PageTags::media($site->poster->id),
        // The entry field's item, and the body's links, the draft's and the private one's too.
        PageTags::content($site->sequel->id),
        PageTags::content($site->draft->id),
        PageTags::content($site->private->id),
    )->not->toContain(PageTags::media($site->hiddenOnly->id));
});

it('tags a 404 for a well-formed ID with that ID, so its publication purges it', function (): void {
    $site = $this->site;
    $record = app(RenderRecord::class);
    $fresh = strtolower((string) Str::ulid());

    $record->start(0);
    $draft = app(ItemQuery::class)->byId($site->draft->id);
    $draftTags = $record->tags();

    $record->start(0);
    $never = app(ItemQuery::class)->byId($fresh);
    $neverTags = $record->tags();

    expect($draft)->toBeNull()
        ->and($draftTags)->toContain(PageTags::content($site->draft->id))
        ->and($never)->toBeNull()
        ->and($neverTags)->toContain(PageTags::content($fresh));
});
