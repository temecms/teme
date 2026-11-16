<?php

declare(strict_types=1);

use App\Domain\Cards\CardMedia;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Site\SiteLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\Switches;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
});

/** D6's full item: the summary without its links, and its fields, word count and body. */
const ITEM_MEMBERS = ['id', 'type', 'title', 'slug', 'path', 'url', 'parent_id', 'visibility', 'language', 'published_at', 'updated_at', 'excerpt', 'featured_image', 'author', 'authors', 'terms', 'ancestors', 'translations', 'fields', 'word_count', 'body'];

/**
 * The statements one drawn answer at this address makes, the registries
 * and settings already read by an earlier one.
 */
function itemCount(string $address): int
{
    test()->getJson($address)->assertOk();
    // The files a request has read are kept for that request only (CardMedia); a drawn answer reads its own.
    app()->forgetInstance(CardMedia::class);

    return count(planStatements(fn () => test()->getJson($address)->assertOk()));
}

it('answers one item in full, every member present', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();

    $answer = $this->getJson('/api/v1/items/'.$site->public->id)->assertOk()->assertHeader('Content-Type', 'application/json')->json();
    $item = $answer['data'];

    expect(array_keys($answer))->toEqualCanonicalizing(['data', 'links'])
        ->and($answer['links'])->toBe(['self' => 'https://example.com/api/v1/items/'.$site->public->id])
        ->and(array_keys($item))->toEqualCanonicalizing(ITEM_MEMBERS)
        ->and($item['id'])->toBe($site->public->id)
        ->and($item['title'])->toBe('Arrival')
        ->and($item['parent_id'])->toBeNull()
        ->and($item['visibility'])->toBe('public')
        ->and($item['word_count'])->toBeInt()->toBeGreaterThan(0)
        ->and(array_keys($item['body']))->toEqualCanonicalizing(['html', 'json', 'media'])
        ->and(array_keys($item['terms']))->toBe(['genre'])
        ->and($item['fields'])->toBeArray()->not->toBe([]);
});

it('names each item\'s language as the site\'s, and an entry field\'s item too', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();

    $before = $this->getJson('/api/v1/items/'.$site->public->id)->assertOk()->json('data');

    expect($before['language'])->toBe(app(SiteLanguage::class)->forContent($site->public->id))->toBe('en-GB')
        ->and($before['fields']['sequel']['language'])->toBe('en-GB')
        ->and(array_keys($before['fields']['sequel']))->toEqualCanonicalizing(['id', 'type', 'language', 'title', 'path', 'url']);

    $folder = cacheOn();

    try {
        siteSetLanguage($site->admin, 'fr');
        $after = $this->getJson('/api/v1/items/'.$site->public->id)->assertOk()->json('data');
    } finally {
        cacheOff($folder);
    }

    expect($after['language'])->toBe('fr')
        ->and($after['fields']['sequel']['language'])->toBe('fr');
});

it('answers 404 not_found for an ID nothing has', function (): void {
    ctAdmin();
    Switches::apiOn();
    $id = strtolower((string) Str::ulid());

    $this->getJson('/api/v1/items/'.$id)
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => 'about:blank',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => "No published item has the ID {$id}.",
            'instance' => '/api/v1/items/'.$id,
            'code' => 'not_found',
        ]);
});

it('answers the catch-all\'s not_found for an ID of the wrong form', function (): void {
    ctAdmin();
    Switches::apiOn();
    $upper = (string) Str::ulid();

    foreach (['/api/v1/items/'.$upper, '/api/v1/items/42'] as $address) {
        $this->getJson($address)->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('detail', "The API has nothing at {$address}. Start at https://example.com/api/v1.");
    }
});

it('reads one item in at most 14 statements, a bare item and a rich one alike, the rich one the same for ten links and twenty', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    $site = DeliverySite::build();
    Switches::apiOn();
    $bare = addrPublished($site->admin, 'Bare');
    $ten = itemRich($site, 'Rich', 10);
    $twenty = itemRich($site, 'Richer', 20);

    $bareCount = itemCount('/api/v1/items/'.$bare->id);
    $tenCount = itemCount('/api/v1/items/'.$ten->id);
    $twentyCount = itemCount('/api/v1/items/'.$twenty->id);

    expect($bareCount)->toBeLessThanOrEqual(14)
        ->and($tenCount)->toBeLessThanOrEqual(14)
        ->and($twentyCount)->toBe($tenCount)
        ->and($this->getJson('/api/v1/items/'.$ten->id)->json('data.body.html'))->toContain('Link 10');
});

it('shows the published revision while a newer draft says something else', function (): void {
    $site = DeliverySite::build();
    Switches::apiOn();

    $response = $this->getJson('/api/v1/items/'.$site->newerDraft->id)->assertOk();

    expect($response->json('data.title'))->toBe('Old title')
        ->and((string) $response->getContent())->not->toContain('SECRET');
});

it('reads one item in as many statements with ten entry fields, ten file fields and ten terms as with one of each', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    $site = DeliverySite::build();
    Switches::apiOn();

    // Nine more entry fields and nine more file fields on movie, shown on the site.
    for ($n = 1; $n <= 9; $n++) {
        StoredFieldRows::store(StoredFieldRows::entry(sprintf('01KA0DE1S1TE00000000EN%04d', $n), "entry_{$n}", ['fieldType' => 'entry']), 20 + $n);
        StoredFieldRows::store(StoredFieldRows::entry(sprintf('01KA0DE1S1TE00000000MD%04d', $n), "file_{$n}", ['fieldType' => 'media']), 40 + $n);
    }

    app(StoredFields::class)->remember();
    app(FieldRegistry::class)->refreshStored();
    $one = itemFielded($site, 'Single', 1);
    $ten = itemFielded($site, 'Tenfold', 10);

    $oneCount = itemCount('/api/v1/items/'.$one->id);
    $tenCount = itemCount('/api/v1/items/'.$ten->id);
    $answer = $this->getJson('/api/v1/items/'.$ten->id)->assertOk()->json('data');

    expect($oneCount)->toBeLessThanOrEqual(14)
        ->and($tenCount)->toBe($oneCount)
        ->and($answer['terms']['genre'])->toHaveCount(10)
        ->and($answer['fields']['entry_9']['title'] ?? null)->toBe('Tenfold target 9')
        ->and($answer['fields']['file_9']['kind'] ?? null)->toBe('image');
});

/**
 * A movie with $count entry fields set, each naming a published item, as
 * many file fields set, each a picture of its own, and as many terms,
 * published; its body one plain paragraph.
 */
function itemFielded(DeliverySite $site, string $title, int $count): Content
{
    $fields = [DeliverySite::SEQUEL => $site->sequel->id, DeliverySite::POSTER => ['id' => $site->poster->id, 'alt' => null, 'decorative' => false]];
    $terms = [$site->genre->id];

    for ($n = 1; $n < $count; $n++) {
        $fields[sprintf('01KA0DE1S1TE00000000EN%04d', $n)] = addrPublished($site->admin, "{$title} target {$n}")->id;
        $fields[sprintf('01KA0DE1S1TE00000000MD%04d', $n)] = ['id' => mediaUpload($site->admin, mediaJpeg(500 + 10 * $count + $n, 300), "{$title} file {$n}.jpg")->id, 'alt' => null, 'decorative' => false];
        $terms[] = typesTerm($site->admin, 'genre', "{$title} genre {$n}")->id;
    }

    $movie = lifecycleDraft($site->author, $title, 'movie');
    $movie->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $title, ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Plain words.']]]]], $fields, $movie->working_revision_id, (string) $site->author->id, $terms));
    listsPublish($movie, $site->admin);

    return $movie->refresh();
}

/**
 * A movie with five terms, every shown field set, and $links links to as
 * many items and as many images in its body, published.
 */
function itemRich(DeliverySite $site, string $title, int $links): Content
{
    $terms = [$site->genre->id];

    for ($n = 1; $n <= 4; $n++) {
        $terms[] = typesTerm($site->admin, 'genre', "{$title} genre {$n}")->id;
    }

    $nodes = [];

    for ($n = 1; $n <= $links; $n++) {
        $target = addrPublished($site->admin, "{$title} target {$n}");
        $image = mediaUpload($site->admin, mediaJpeg(400 + 30 * $links + $n, 400), "{$title} picture {$n}.jpg");
        $nodes[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Link {$n}", 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/'.$target->id]]]]]];
        $nodes[] = ['type' => 'image', 'attrs' => ['id' => strtoupper((string) Str::ulid()), 'mediaId' => $image->id, 'alt' => "Picture {$n}"]];
    }

    $fields = [
        BuiltInFields::EXCERPT => 'A rich one.',
        BuiltInFields::FEATURED_IMAGE => ['id' => $site->shell->id, 'alt' => null, 'decorative' => false],
        DeliverySite::RELEASE => '2020-01-01',
        DeliverySite::PREMIERE => '2020-01-01T10:00:00Z',
        DeliverySite::RUNTIME => 90,
        DeliverySite::RATING => 'r',
        DeliverySite::CINEMAS => true,
        DeliverySite::TRAILER => 'https://video.example/rich',
        DeliverySite::TAGLINE => 'Rich.',
        DeliverySite::POSTER => ['id' => $site->poster->id, 'alt' => null, 'decorative' => false],
        DeliverySite::SEQUEL => $site->sequel->id,
    ];

    $movie = lifecycleDraft($site->author, $title, 'movie');
    $movie->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $title, ['type' => 'doc', 'content' => $nodes], $fields, $movie->working_revision_id, (string) $site->author->id, $terms));
    listsPublish($movie, $site->admin);

    return $movie->refresh();
}
