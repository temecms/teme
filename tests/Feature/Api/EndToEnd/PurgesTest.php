<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Operations\Input\UpdateFieldInput;
use App\Domain\Fields\Operations\UpdateField;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use App\Domain\Users\Operations\Input\UpdateProfileInput;
use App\Domain\Users\Operations\UpdateProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\Api\CatalogueProbe;
use Tests\Fixtures\Api\DeliverySite;
use Tests\Fixtures\Api\EndToEnd;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

// The join (I-3324): every change made through its real Operation, which
// purges after commit, and every answer read through the whole stack with
// the page cache on, so each test proves a real stored answer is purged by
// the change that should purge it, and kept by one that shouldn't.

beforeEach(function (): void {
    $this->freezeSecond();
    $this->site = DeliverySite::build();
    Switches::apiOn();
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

/**
 * Asks for each address twice, so each is stored: a MISS, then a HIT.
 */
function e2eStored(string ...$addresses): void
{
    foreach ($addresses as $address) {
        EndToEnd::get($address)->assertHeader('X-Teme-Cache', 'MISS');
        EndToEnd::get($address)->assertHeader('X-Teme-Cache', 'HIT');
    }
}

it('shows an edit at once after it is published', function (): void {
    $site = $this->site;
    $item = '/api/v1/items/'.$site->public->id;
    $list = '/api/v1/items?type=movie';
    $untouched = '/api/v1/items/'.$site->sequel->id;
    e2eStored($item, $list, $untouched);

    EndToEnd::edit($site->public, $site->admin, 'Arrival, recut');
    listsPublish($site->public, $site->admin);

    EndToEnd::get($item)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->assertJsonPath('data.title', 'Arrival, recut');
    $listed = EndToEnd::get($list)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data');
    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect(array_column($listed, 'title', 'id')[$site->public->id] ?? null)->toBe('Arrival, recut');
});

it('answers 404 at once after the item is made private', function (): void {
    $site = $this->site;
    $item = '/api/v1/items/'.$site->public->id;
    $byPath = EndToEnd::byPath((string) Addresses::relativeUrl($site->public));
    $list = '/api/v1/items';
    $file = '/api/v1/media/'.$site->poster->id;
    $untouched = '/api/v1/items/'.$site->sequel->id;
    e2eStored($item, $byPath, $list, $file, $untouched);

    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($site->public->id, 'private', null, (string) $site->admin->id));

    $never = strtolower((string) Str::ulid());
    $hidden = EndToEnd::get($item)->assertNotFound()->assertJsonPath('code', 'not_found');
    $missing = EndToEnd::get('/api/v1/items/'.$never)->assertNotFound();

    // Exactly as an item that never existed: the same words but for the ID asked.
    expect(str_replace($site->public->id, '{id}', (string) $hidden->getContent()))->toBe(str_replace($never, '{id}', (string) $missing->getContent()));

    EndToEnd::get($byPath)->assertNotFound()->assertJsonPath('code', 'not_found');
    EndToEnd::get($file)->assertNotFound()->assertJsonPath('code', 'not_found');
    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect(array_column(EndToEnd::get($list)->assertOk()->json('data'), 'id'))->not->toContain($site->public->id)->toContain($site->sequel->id);
});

it('gives a protected item without its words at once after it is made protected', function (): void {
    $site = $this->site;
    $item = '/api/v1/items/'.$site->public->id;
    $list = '/api/v1/items?type=movie';
    $file = '/api/v1/media/'.$site->poster->id;
    e2eStored($item, $list, $file);

    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($site->public->id, 'protected', 'opensesame', (string) $site->admin->id));

    $locked = EndToEnd::get($item)->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $summary = collect(EndToEnd::get($list)->assertOk()->json('data'))->firstWhere('id', $site->public->id);

    expect($locked->json('data.visibility'))->toBe('protected')
        ->and($locked->json('data.body'))->toBeNull()
        ->and($locked->json('data.fields'))->toBe([])
        ->and($locked->json('data.excerpt'))->toBeNull()
        ->and($locked->json('data.featured_image'))->toBeNull()
        ->and((string) $locked->getContent())->not->toContain('Louise Banks')->not->toContain('opensesame')
        ->and($summary)->toHaveKey('excerpt', null)->toHaveKey('featured_image', null);

    // A file only a protected item shows is answered as one that doesn't exist.
    EndToEnd::get($file)->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('gives a newly published item on the next draw of its type\'s list', function (): void {
    $site = $this->site;
    $list = '/api/v1/items?type=movie';
    $all = '/api/v1/items';
    $item = '/api/v1/items/'.$site->draft->id;
    $untouched = '/api/v1/terms/'.$site->genre->id;
    e2eStored($list, $all, $untouched);
    EndToEnd::get($item)->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS');

    expect(array_column(EndToEnd::get($list)->json('data'), 'id'))->not->toContain($site->draft->id);

    listsPublish($site->draft, $site->admin);

    expect(array_column(EndToEnd::get($list)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data'), 'id'))->toContain($site->draft->id)
        ->and(array_column(EndToEnd::get($all)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data'), 'id'))->toContain($site->draft->id);

    // Its stored 404 goes with the publication, and a term it isn't filed under keeps its answer.
    EndToEnd::get($item)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->assertJsonPath('data.title', 'Unfinished');
    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
});

it('shows a term\'s new name on every item that carries it', function (): void {
    $site = $this->site;
    $item = '/api/v1/items/'.$site->public->id;
    $filtered = '/api/v1/items?term='.$site->genre->id;
    $term = '/api/v1/terms/'.$site->genre->id;
    $terms = '/api/v1/terms?taxonomy=genre';
    $untouched = '/api/v1/types';
    e2eStored($item, $filtered, $term, $terms, $untouched);

    app(UpdateTerm::class)->execute(new SaveTermInput('genre', 'Speculative fiction', 'speculative-fiction', null, 'Films about what might be.', (string) $site->admin->id, termId: $site->genre->id));

    expect(EndToEnd::get($item)->assertOk()->json('data.terms.genre.0.name'))->toBe('Speculative fiction')
        ->and(collect(EndToEnd::get($filtered)->assertOk()->json('data'))->firstWhere('id', $site->public->id)['terms']['genre'][0]['name'] ?? null)->toBe('Speculative fiction')
        ->and(EndToEnd::get($term)->assertOk()->json('data.name'))->toBe('Speculative fiction')
        ->and(EndToEnd::get($term)->json('data.path'))->toContain('speculative-fiction')
        ->and(array_column(EndToEnd::get($terms)->assertOk()->json('data'), 'name'))->toBe(['Speculative fiction']);

    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
});

it('lists a new term on the next draw of its taxonomy\'s terms', function (): void {
    $site = $this->site;
    $terms = '/api/v1/terms?taxonomy=genre';
    $all = '/api/v1/terms';
    $untouched = '/api/v1/items/'.$site->sequel->id;
    e2eStored($terms, $all, $untouched);

    app(CreateTerm::class)->execute(new SaveTermInput('genre', 'Drama', null, null, null, (string) $site->admin->id));

    expect(array_column(EndToEnd::get($terms)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data'), 'name'))->toBe(['Drama', 'Science fiction'])
        ->and(array_column(EndToEnd::get($all)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data'), 'name'))->toContain('Drama');

    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
});

/**
 * Gives the sequel the poster as its featured image with no alt text of
 * its own, so the library's is given, and publishes it.
 */
function e2ePosterOnSequel(DeliverySite $site): void
{
    EndToEnd::edit($site->sequel, $site->admin, 'Arrival II', [BuiltInFields::FEATURED_IMAGE => ['id' => $site->poster->id, 'alt' => null, 'decorative' => false]]);
    listsPublish($site->sequel, $site->admin);
}

it('shows a featured image\'s new alt text at once', function (): void {
    $site = $this->site;
    e2ePosterOnSequel($site);
    $item = '/api/v1/items/'.$site->sequel->id;
    $list = '/api/v1/items?type=movie';
    $file = '/api/v1/media/'.$site->poster->id;
    $poster = '/api/v1/items/'.$site->public->id;
    $untouched = '/api/v1/items/'.$site->about->id;
    e2eStored($item, $list, $file, $poster, $untouched);

    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($site->poster->id, (string) $site->admin->id, (string) $site->poster->title, 'A poster of the shell at dusk', null, null));

    expect(EndToEnd::get($item)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data.featured_image.alt'))->toBe('A poster of the shell at dusk')
        ->and(collect(EndToEnd::get($list)->assertOk()->json('data'))->firstWhere('id', $site->sequel->id)['featured_image']['alt'] ?? null)->toBe('A poster of the shell at dusk')
        ->and(EndToEnd::get($file)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data.alt'))->toBe('A poster of the shell at dusk')
        // Arrival's poster field has alt text of its own, which stays.
        ->and(EndToEnd::get($poster)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data.fields.poster.alt'))->toBe('The poster');

    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
});

it('leaves out a file at once after it goes to the trash', function (): void {
    $site = $this->site;
    e2ePosterOnSequel($site);
    $item = '/api/v1/items/'.$site->sequel->id;
    $file = '/api/v1/media/'.$site->poster->id;
    $poster = '/api/v1/items/'.$site->public->id;
    $untouched = '/api/v1/items/'.$site->about->id;
    e2eStored($item, $file, $poster, $untouched);

    app(TrashMedia::class)->execute(new MediaActionInput($site->poster->id, (string) $site->admin->id));

    expect(EndToEnd::get($item)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data.featured_image'))->toBeNull()
        ->and(EndToEnd::get($poster)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data.fields.poster'))->toBeNull();

    EndToEnd::get($file)->assertNotFound()->assertJsonPath('code', 'not_found');
    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
});

it('shows an author\'s new name at once', function (): void {
    $site = $this->site;
    $slug = (string) DB::table('users')->where('id', $site->author->id)->value('slug');
    $item = '/api/v1/items/'.$site->public->id;
    $list = '/api/v1/items';
    $byAuthor = '/api/v1/items?author='.$slug;
    $untouched = '/api/v1/types';
    e2eStored($item, $list, $byAuthor, $untouched);

    app(UpdateProfile::class)->execute(new UpdateProfileInput((string) $site->author->id, 'Ada Obi-Banks', 'ada@example.com', (string) $site->author->id));

    expect(EndToEnd::get($item)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data.author.name'))->toBe('Ada Obi-Banks')
        ->and(collect(EndToEnd::get($list)->assertOk()->json('data'))->firstWhere('id', $site->public->id)['author']['name'] ?? null)->toBe('Ada Obi-Banks')
        ->and(collect(EndToEnd::get($byAuthor)->assertOk()->json('data'))->firstWhere('id', $site->public->id)['author']['name'] ?? null)->toBe('Ada Obi-Banks');

    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
});

it('answers api_off at once after the switch is turned off, and the item again after it is turned on', function (): void {
    $item = '/api/v1/items/'.$this->site->public->id;
    e2eStored($item);

    Switches::apiOff();

    EndToEnd::get($item)->assertNotFound()->assertHeader('X-Teme-Cache', 'MISS')->assertJsonPath('code', 'api_off');
    // The answer of an address with no query is stored, ten seconds, tagged seo (D16).
    EndToEnd::get($item)->assertNotFound()->assertHeader('X-Teme-Cache', 'HIT')->assertJsonPath('code', 'api_off');

    expect(cacheEntry($item)?->tags)->toContain('seo');

    Switches::apiOn();

    expect(cacheEntry($item))->toBeNull();

    EndToEnd::get($item)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->assertJsonPath('data.title', 'Arrival');
});

it('answers 404 for a file once the only item showing it is unpublished', function (): void {
    $site = $this->site;
    $file = '/api/v1/media/'.$site->poster->id;
    $item = '/api/v1/items/'.$site->public->id;
    $untouched = '/api/v1/items/'.$site->sequel->id;
    e2eStored($file, $item, $untouched);

    app(UnpublishContent::class)->execute(new ContentActionInput($site->public->id, (string) $site->admin->id));

    EndToEnd::get($file)->assertNotFound()->assertJsonPath('code', 'not_found');
    EndToEnd::get($item)->assertNotFound()->assertJsonPath('code', 'not_found');
    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');
});

it('lists a newly published item in llms.txt on its next draw', function (): void {
    $site = $this->site;
    Switches::llmsOn();
    e2eStored('/llms.txt');

    expect((string) EndToEnd::get('/llms.txt')->getContent())->not->toContain('Unfinished');

    listsPublish($site->draft, $site->admin);
    $path = rtrim((string) Addresses::relativeUrl($site->draft->refresh()), '/');

    expect((string) EndToEnd::get('/llms.txt')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->getContent())->toContain('[Unfinished](http://localhost'.$path.'.md)');

    EndToEnd::get($path.'.md')->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
});

it('shows a moved item\'s new address in by-path, old address moved true', function (): void {
    $site = $this->site;
    $old = (string) Addresses::relativeUrl($site->public);
    $item = '/api/v1/items/'.$site->public->id;
    e2eStored(EndToEnd::byPath($old), $item);

    expect(EndToEnd::get(EndToEnd::byPath($old))->json('meta'))->toBe(['requested_path' => $old, 'moved' => false]);

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($site->public->id, (string) $site->public->refresh()->working_revision_id, (string) $site->admin->id, slug: 'arrival-recut'));
    $new = (string) Addresses::relativeUrl($site->public->refresh());

    $moved = EndToEnd::get(EndToEnd::byPath($old))->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    $current = EndToEnd::get(EndToEnd::byPath($new))->assertOk();

    expect($new)->not->toBe($old)->toContain('arrival-recut')
        ->and($moved->json('meta'))->toBe(['requested_path' => $old, 'moved' => true])
        ->and($moved->json('data.path'))->toBe($new)
        ->and($current->json('meta'))->toBe(['requested_path' => $new, 'moved' => false])
        ->and(EndToEnd::get($item)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data.path'))->toBe($new);
});

it('leaves out a field at once after it is hidden on the site', function (): void {
    $site = $this->site;
    $item = '/api/v1/items/'.$site->public->id;
    $types = '/api/v1/types';
    e2eStored($item, $types);

    expect(EndToEnd::get($item)->json('data.fields'))->toHaveKey('tagline');

    $tagline = app(StoredFields::class)->find(DeliverySite::TAGLINE) ?? throw new LogicException('No tagline field.');
    app(UpdateField::class)->execute(new UpdateFieldInput('movie', DeliverySite::TAGLINE, FieldDefinition::fromArray([...$tagline->toArray(), 'key' => '', 'show' => 'hidden']), (string) $site->admin->id));

    $movie = collect(EndToEnd::get($types)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data'))->firstWhere('key', 'movie');

    expect(EndToEnd::get($item)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data.fields'))->not->toHaveKey('tagline')->toHaveKey('runtime')
        ->and((string) EndToEnd::get($item)->getContent())->not->toContain('Why are they here?')
        ->and(array_column($movie['fields'] ?? [], 'handle'))->not->toContain('tagline')->toContain('runtime');
});

it('refuses a type at once after it is made not public', function (): void {
    $site = $this->site;
    $series = ['key' => 'series', 'singular' => 'Series', 'plural' => 'Series', 'segment' => 'series', 'taxonomies' => [], 'supports' => [], 'comments' => null];
    ctCreate($site->admin, $series);
    $list = '/api/v1/items?type=series';
    $types = '/api/v1/types';
    e2eStored($list, $types);

    expect(array_column(EndToEnd::get($types)->json('data'), 'key'))->toContain('series');

    app(UpdateType::class)->execute(new UpdateTypeInput(ctDefinition([...$series, 'public' => false, 'archive' => false, 'searchable' => false]), (string) $site->admin->id));

    EndToEnd::get($list)->assertStatus(400)->assertJsonPath('code', 'invalid_parameter')->assertJsonPath('errors.0.parameter', 'type');

    expect(array_column(EndToEnd::get($types)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data'), 'key'))->not->toContain('series')->toContain('movie');
});

it('names the site anew at once after its settings change', function (): void {
    $site = $this->site;
    e2eStored('/api/v1');

    CatalogueProbe::general($site->admin, ['site_title' => 'The Quay Times']);

    EndToEnd::get('/api/v1')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->assertJsonPath('data.name', 'The Quay Times');
});

it('answers 404 at once after the item goes to the trash', function (): void {
    $site = $this->site;
    $item = '/api/v1/items/'.$site->sequel->id;
    $byPath = EndToEnd::byPath((string) Addresses::relativeUrl($site->sequel));
    $list = '/api/v1/items?type=movie';
    $untouched = '/api/v1/items/'.$site->about->id;
    e2eStored($item, $byPath, $list, $untouched);

    app(TrashContent::class)->execute(new ContentActionInput($site->sequel->id, (string) $site->admin->id));

    EndToEnd::get($item)->assertNotFound()->assertJsonPath('code', 'not_found');
    EndToEnd::get($byPath)->assertNotFound()->assertJsonPath('code', 'not_found');
    $listed = EndToEnd::get($list)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data');
    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect(array_column($listed, 'id'))->not->toContain($site->sequel->id)->toContain($site->public->id);
});

it('lists a scheduled item at once when the scheduler publishes it', function (): void {
    $site = $this->site;
    // A week on, the publication is due; the list is stored before the scheduler runs.
    $this->travel(8)->days();
    $item = '/api/v1/items/'.$site->scheduled->id;
    $list = '/api/v1/items?type=movie';
    $untouched = '/api/v1/items?type=page';
    e2eStored($list, $untouched);
    EndToEnd::get($item)->assertNotFound();

    expect(array_column(EndToEnd::get($list)->json('data'), 'id'))->not->toContain($site->scheduled->id);

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    $listed = EndToEnd::get($list)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data');
    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect(array_column($listed, 'title', 'id')[$site->scheduled->id] ?? null)->toBe('Tomorrow')
        ->and(EndToEnd::get($item)->assertOk()->json('data.title'))->toBe('Tomorrow');
});

it('leaves an item off its list at once after it is dated ahead', function (): void {
    $site = $this->site;
    $item = '/api/v1/items/'.$site->sequel->id;
    $list = '/api/v1/items?type=movie';
    $untouched = '/api/v1/items/'.$site->about->id;
    e2eStored($item, $list, $untouched);

    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput($site->sequel->id, (string) $site->sequel->refresh()->working_revision_id, (string) $site->admin->id, publishedAt: now()->addDays(2)->toIso8601String()));

    $listed = EndToEnd::get($list)->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->json('data');
    EndToEnd::get($item)->assertNotFound()->assertJsonPath('code', 'not_found');
    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect(array_column($listed, 'id'))->not->toContain($site->sequel->id)->toContain($site->public->id);
});

it('leaves an item out of llms.txt and answers no .md page at once after it is made private', function (): void {
    $site = $this->site;
    Switches::llmsOn();
    $path = rtrim((string) Addresses::relativeUrl($site->sequel), '/');
    $untouched = rtrim((string) Addresses::relativeUrl($site->about), '/').'.md';
    e2eStored('/llms.txt', $path.'.md', $untouched);

    expect((string) EndToEnd::get('/llms.txt')->getContent())->toContain($path.'.md');

    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($site->sequel->id, 'private', null, (string) $site->admin->id));

    $llms = (string) EndToEnd::get('/llms.txt')->assertOk()->assertHeader('X-Teme-Cache', 'MISS')->getContent();
    EndToEnd::get($path.'.md')->assertNotFound();
    EndToEnd::get($untouched)->assertOk()->assertHeader('X-Teme-Cache', 'HIT');

    expect($llms)->not->toContain($path.'.md')->not->toContain('Arrival II')->toContain('# ');
});
