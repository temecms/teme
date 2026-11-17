<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Fields\BuiltInFields;
use App\Domain\People\Operations\Input\SetBylinesInput;
use App\Domain\People\Operations\SetBylines;
use App\Domain\Plugins\Doors\CurrentJobActor;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ItemQuery;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\ReadRefused;
use Tests\Fixtures\Languages\LanguageSite;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    PluginFixtures::use('librarian');

    $this->reader = fn (array $capabilities = ['content.read']): ContentReader => app(PluginDoors::class)
        ->for('tests/librarian', 'librarian', $capabilities, [])[ContentReader::class];
});

afterEach(function (): void {
    PluginFixtures::forget();
    app(CurrentJobActor::class)->clear();
});

/**
 * The operator's actor, made as the shell would make it, with no elevation left behind.
 */
function crOperator(): JobActor
{
    app()->instance(Elevation::class, new OperatorElevation);
    $actor = JobActor::operator();
    app()->forgetInstance(Elevation::class);

    return $actor;
}

/**
 * Publishes an item with this title, at this many minutes before now.
 */
function crPublished(User $author, string $title, int $minutesAgo, string $type = 'post'): Content
{
    $item = addrPublished($author, $title, $type);
    DB::table('contents')->where('id', $item->id)->update(['published_at' => now()->subMinutes($minutesAgo)]);

    return $item->refresh();
}

/**
 * Every title the reader lists for this query, page after page.
 *
 * @return array{titles: list<string>, pages: int}
 */
function crAll(ContentReader $reader, ItemQuery $query): array
{
    $titles = [];
    $pages = 0;
    $after = null;

    do {
        $page = $reader->published(new ItemQuery($query->types, $query->language, $query->terms, $query->publishedSince, $after, $query->limit));
        $titles = [...$titles, ...array_map(fn (ItemRef $item): string => $item->title, $page->items)];
        $after = $page->next;
        $pages++;
    } while ($after !== null && $pages < 20);

    return ['titles' => $titles, 'pages' => $pages];
}

it('lists published items newest first, in pages whose cursors chain to the end', function (): void {
    $author = User::factory()->create()->assignRole('editor');

    foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $index => $title) {
        crPublished($author, $title, 50 - $index * 10);
    }

    $first = ($this->reader)()->published(new ItemQuery(limit: 2));

    expect(array_map(fn (ItemRef $item): string => $item->title, $first->items))->toBe(['Five', 'Four'])
        ->and($first->next)->toBeString()
        ->and(crAll(($this->reader)(), new ItemQuery(limit: 2)))->toBe(['titles' => ['Five', 'Four', 'Three', 'Two', 'One'], 'pages' => 3]);
});

it('filters by type, terms and the moment published since', function (): void {
    $author = User::factory()->create()->assignRole('editor');
    $news = taxonomyTerm('category', 'News');
    $sport = taxonomyTerm('category', 'Sport');
    $filed = crPublished($author, 'Filed in news', 30);
    crPublished($author, 'Not filed', 20);
    crPublished($author, 'A page', 10, 'page');
    $content = Content::query()->findOrFail($filed->id);
    docSave($content, $author, docWith(docText('Filed')), terms: [$news->id]);
    listsPublish($content, $author);
    $sporty = crPublished($author, 'Sporty', 40);
    docSave($sporty, $author, docWith(docText('Sport')), terms: [$sport->id]);
    listsPublish($sporty, $author);
    DB::table('contents')->where('id', $filed->id)->update(['published_at' => now()->subMinutes(30)]);
    DB::table('contents')->where('id', $sporty->id)->update(['published_at' => now()->subMinutes(40)]);

    expect(crAll(($this->reader)(), new ItemQuery(types: ['page']))['titles'])->toBe(['A page'])
        ->and(crAll(($this->reader)(), new ItemQuery(terms: ['category' => ['news', 'sport']]))['titles'])->toBe(['Filed in news', 'Sporty'])
        ->and(crAll(($this->reader)(), new ItemQuery(terms: ['category' => ['news']]))['titles'])->toBe(['Filed in news'])
        ->and(crAll(($this->reader)(), new ItemQuery(types: ['post'], publishedSince: now()->subMinutes(25)->toDateTimeImmutable()))['titles'])->toBe(['Not filed']);
});

it('filters by language, and lists every shown language without one', function (): void {
    $site = LanguageSite::build();

    expect(crAll(($this->reader)(), new ItemQuery(types: ['post'], language: 'fr'))['titles'])->toBe(['Bonjour'])
        ->and(crAll(($this->reader)(), new ItemQuery(types: ['post'], language: 'en-GB'))['titles'])->toEqualCanonicalizing(['Hello', 'Alone'])
        ->and(crAll(($this->reader)(), new ItemQuery(types: ['post']))['titles'])->toEqualCanonicalizing(['Hello', 'Bonjour', 'Alone']);
});

it('never lists a draft, a private or password-protected item, a trashed item or one of a type with no public address', function (): void {
    $author = User::factory()->create()->assignRole('editor');
    $admin = User::factory()->create()->assignRole('administrator');
    crPublished($author, 'Shown', 5);
    lifecycleDraft($author, 'A draft');
    $private = crPublished($author, 'Private', 6);
    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($private->id, 'private', null, (string) $author->id));
    $protected = crPublished($author, 'Protected', 7);
    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($protected->id, 'protected', 'harbour-secret', (string) $author->id));
    pageTrash(crPublished($author, 'Trashed', 8), $author);
    $hidden = crPublished($admin, 'In a region', 9);
    DB::table('contents')->where('id', $hidden->id)->update(['type' => 'region']);

    expect(crAll(($this->reader)(), new ItemQuery)['titles'])->toBe(['Shown'])
        ->and(crAll(($this->reader)(), new ItemQuery(types: ['region']))['titles'])->toBe([]);
});

it('gives each item an absolute address made when read, following a changed site address', function (): void {
    $author = User::factory()->create()->assignRole('editor');
    $item = crPublished($author, 'Harbour', 5);

    URL::forceRootUrl('http://one.example');
    $before = ($this->reader)()->published(new ItemQuery)->items[0];
    URL::forceRootUrl('http://two.example');
    $after = ($this->reader)()->published(new ItemQuery)->items[0];

    expect($before->id)->toBe($item->id)
        ->and($before->url)->toStartWith('http://one.example/')
        ->and($after->url)->toStartWith('http://two.example/')
        ->and($after->status)->toBe('published');
});

it('refuses a cursor it did not give, and a query outside its bounds', function (?string $after, int $limit): void {
    expect(fn () => ($this->reader)()->published(new ItemQuery(after: $after, limit: $limit)))->toThrow(InvalidArgumentException::class);
})->with([
    'not a cursor' => ['not a cursor!', 10],
    'another list’s cursor' => [rtrim(strtr(base64_encode('["position","3","01k0000000000000000000000a"]'), '+/', '-_'), '='), 10],
    'a broken date' => [rtrim(strtr(base64_encode('["newest","yesterday","01k0000000000000000000000a"]'), '+/', '-_'), '='), 10],
    'no items' => [null, 0],
    'too many' => [null, 201],
]);

it('is not offered without content.read, and a plugin asking for it without that grant fails to start', function (): void {
    $doors = app(PluginDoors::class);

    expect($doors->for('tests/librarian', 'librarian', ['content.read'], []))->toHaveKey(ContentReader::class)
        ->and($doors->for('tests/librarian', 'librarian', ['content.write', 'jobs'], []))->not->toHaveKey(ContentReader::class);

    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/librarian',
        'code' => 'librarian',
        'version' => '1.0.0',
        'consent' => ['capabilities' => ['content.write', 'jobs'], 'hosts' => [], 'hooks' => []],
    ]);
    app(PluginLoader::class)->load();

    expect(app(LoadedPlugins::class)->running())->not->toHaveKey('tests/librarian');
});

it('reads the working revision for a job running as a person who may edit it', function (): void {
    $editor = User::factory()->create(['name' => 'Eddie'])->assignRole('editor');
    $other = User::factory()->create(['name' => 'Olga'])->assignRole('author');
    $news = taxonomyTerm('category', 'News');
    $item = crPublished($editor, 'Harbour', 5);
    docSave($item, $editor, docWith(docText('Working words')), terms: [$news->id]);
    app(SetBylines::class)->execute(new SetBylinesInput((string) $editor->id, $item->id, ["user:{$other->id}", "user:{$editor->id}"]));
    $item->refresh();
    app(CurrentJobActor::class)->set(JobActor::person($editor->id));

    $draft = ($this->reader)()->draft($item->id, (string) $editor->id);

    expect($draft->item->id)->toBe($item->id)
        ->and($draft->revisionId)->toBe((string) $item->working_revision_id)
        ->and($draft->slug)->toBe((string) $item->slug)
        ->and($draft->body)->toBe((array) $item->workingRevision->document)
        ->and(json_encode($draft->body))->toContain('Working words')
        ->and($draft->fields)->toBeArray()
        ->and($draft->terms)->toBe(['category' => ['news']])
        ->and($draft->credits)->toBe([(string) $other->id, (string) $editor->id])
        ->and($draft->translations)->toBe([]);
});

it('reads a draft’s fields by handle, and its other language versions', function (): void {
    $site = LanguageSite::build();
    $item = Content::query()->findOrFail($site->post);
    docSave($item, $site->editor, docWith(docText('Hello again')), fields: [BuiltInFields::SEO_TITLE => 'Harbour SEO']);
    app(CurrentJobActor::class)->set(JobActor::person($site->editor->id));

    $draft = ($this->reader)()->draft($site->post, (string) $site->editor->id);

    expect($draft->fields['seo_title'] ?? null)->toBe('Harbour SEO')
        ->and(array_map(fn (ItemRef $version): string => $version->title, $draft->translations))->toEqualCanonicalizing(['Bonjour', 'Hola'])
        ->and(array_map(fn (ItemRef $version): string => $version->language, $draft->translations))->toEqualCanonicalizing(['fr', 'es'])
        ->and($draft->credits)->toBe([]);
});

it('refuses a draft for another person, the operator, and a person who can’t edit it', function (Closure $actor, string $words): void {
    $editor = User::factory()->create()->assignRole('editor');
    $author = User::factory()->create()->assignRole('author');
    $item = crPublished($editor, 'Harbour', 5);
    [$current, $asked] = $actor($editor, $author);

    if ($current !== false) {
        app(CurrentJobActor::class)->set($current);
    }

    expect(fn () => ($this->reader)()->draft($item->id, $asked))->toThrow(ReadRefused::class, $words);
})->with([
    'another person' => [fn (User $editor, User $author): array => [JobActor::person($editor->id), (string) $author->id], 'A plugin writes only for the person who started this work.'],
    'the operator' => [fn (User $editor, User $author): array => [crOperator(), (string) $editor->id], 'A plugin writes only for the person who started this work.'],
    'a person who can’t edit it' => [fn (User $editor, User $author): array => [JobActor::person($author->id), (string) $author->id], 'You can’t edit this item, so its draft can’t be read for you.'],
]);
