<?php

declare(strict_types=1);

use App\Domain\Import\File\ContentBlocks;
use App\Domain\Import\File\Jobs\ReadImportFile;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\AssignImportReaderIds;
use App\Domain\Import\Operations\ConnectImportSource;
use App\Domain\Import\Operations\FinishImportUpload;
use App\Domain\Import\Operations\Input\AssignImportReaderIdsInput;
use App\Domain\Import\Operations\Input\ConnectImportSourceInput;
use App\Domain\Import\Operations\Input\FinishImportUploadInput;
use App\Domain\Import\Operations\Input\ReceiveImportPieceInput;
use App\Domain\Import\Operations\Input\StartImportUploadInput;
use App\Domain\Import\Operations\ReceiveImportPiece;
use App\Domain\Import\Operations\StartImportUpload;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Readers\ImportReaders;
use App\Domain\Site\AddressSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Inertia\Middleware;
use Tests\Fixtures\Import\ExportFiles;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\FileImports;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once dirname(__DIR__, 2).'/Fixtures/plugins/readers/src/Readers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    Storage::fake('local');
    app()->instance(ContentBlocks::class, ExportFiles::blocks());
    CarbonImmutable::setTestNow();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    config(['teme.import.reader_page_size' => null]);
});

/**
 * Enables the readers fixture, which reads the fake export, agreeing to what it asks.
 */
function readersEnabled(): void
{
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);

    PluginFixtures::use('readers');
    $manifest = app(PluginRegistry::class)->find('tests/readers')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/readers', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();

    app()->forgetInstance(Elevation::class);
    app()->forgetInstance(ImportReaders::class);
}

/**
 * The key of the fixture's reader, which an upload and a file name.
 */
function fixtureReaderKey(): string
{
    return app(ImportReaders::class)->all()[0]->key();
}

/**
 * A fake export of a site: a person, a term, a picture, a post and a draft page.
 *
 * @return array<string, mixed>
 */
function fakeSiteExport(): array
{
    return [
        'system' => 'Fake export',
        'version' => '2',
        'site' => 'https://old.example.com',
        'languages' => ['en'],
        'items' => [
            ['kind' => 'person', 'id' => 'p1', 'login' => 'ada', 'email' => 'ada@example.com', 'displayName' => 'Ada'],
            ['kind' => 'term', 'id' => 't1', 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news'],
            ['kind' => 'media', 'id' => 'm1', 'url' => 'https://old.example.com/a.jpg', 'mime' => 'image/jpeg', 'alt' => 'A'],
            ['kind' => 'post', 'id' => 'i1', 'title' => 'Hello', 'slug' => 'hello', 'status' => 'published', 'publishedAt' => '2026-01-02 03:04:05', 'oldAddress' => 'https://old.example.com/hello/', 'content' => '<p>Hi there</p>', 'authorId' => 'p1', 'termIds' => ['t1']],
            ['kind' => 'page', 'id' => 'i2', 'title' => 'About', 'slug' => 'about', 'status' => 'draft', 'content' => 'About us', 'format' => 'plain'],
        ],
    ];
}

/**
 * Uploads a reader's file through the real upload operations, as its uploader:
 * the start, the one piece and the finish, which queues the read.
 */
function readerUploaded(string $name, string $bytes, string $reader): ImportFile
{
    $actor = (string) test()->admin->id;
    $started = app(StartImportUpload::class)->execute(new StartImportUploadInput($name, strlen($bytes), $actor, $reader));
    app(ReceiveImportPiece::class)->execute(new ReceiveImportPieceInput($started['fileId'], 0, $bytes, $actor));
    app(FinishImportUpload::class)->execute(new FinishImportUploadInput($started['fileId'], $actor));

    return ImportFile::query()->findOrFail($started['fileId']);
}

/**
 * Reads the file to its end as its uploader, running each part the read queues.
 */
function readerReadToEnd(ImportFile $file): int
{
    $parts = 0;
    $job = new ReadImportFile($file->id, JobActor::person($file->uploaded_by));
    $fake = Queue::getFacadeRoot();

    while ($job !== null) {
        Queue::fake();
        $job->handle();
        $parts++;
        $queued = Queue::pushedJobs()[ReadImportFile::class] ?? [];
        $job = $queued === [] ? null : $queued[0]['job'];
    }

    Queue::swap($fake);

    return $parts;
}

/**
 * Starts an import of a ready reader file and works the database queue to its end,
 * as PolylangSite::import does: sync-queue imports leave the settings memo stale
 * under RefreshDatabase's wrapping transaction.
 */
function readerImport(ImportFile $file): ImportRun
{
    config(['queue.default' => 'database', 'queue.connections.database-long.driver' => 'database']);

    $run = FileImports::run($file);

    for ($jobs = 0; $jobs < 2000 && DB::table('jobs')->exists(); $jobs++) {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'long,default', '--once' => true, '--sleep' => 0]);
    }

    app()->forgetInstance(SiteLanguages::class);
    app()->forgetInstance(AddressSettings::class);
    app()->forgetScopedInstances();

    return ImportRun::query()->findOrFail($run->id);
}

it('reads a reader’s file into a ready file and a source of the reader’s kind', function (): void {
    readersEnabled();

    $file = readerUploaded('site.json', json_encode(fakeSiteExport(), JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    $file->refresh();
    $source = ImportSource::query()->findOrFail($file->source_id);

    expect($file->state)->toBe('ready')
        ->and($file->site_address)->toBe('https://old.example.com')
        ->and($source->address)->toBe('https://old.example.com')
        ->and($source->kind)->toBe(fixtureReaderKey())
        ->and(DB::table('import_file_rows')->where('file_id', $file->id)->where('list', 'content')->count())->toBe(2);
});

it('imports a reader’s file through the engine: posts, pages, terms, people and pictures', function (): void {
    readersEnabled();

    $file = readerUploaded('site.json', json_encode(fakeSiteExport(), JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    $run = FileImports::run($file->refresh());

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(DB::table('contents')->where('title', 'Hello')->where('status', 'published')->exists())->toBeTrue()
        ->and(DB::table('contents')->where('title', 'About')->where('status', 'draft')->exists())->toBeTrue();
});

it('refuses a reader’s file it can’t read, with the reader’s sentence', function (): void {
    readersEnabled();

    $file = readerUploaded('site.json', '<rss></rss>', fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->state)->toBe('refused')
        ->and($file->refused)->toBe('This file isn’t a fake export: it isn’t JSON.');
});

it('refuses a file of a type the reader doesn’t read, and a reader that is no longer on the site', function (): void {
    readersEnabled();

    $start = fn (string $name, ?string $reader): mixed => app(StartImportUpload::class)->execute(
        new StartImportUploadInput($name, 10, (string) $this->admin->id, $reader),
    );

    expect(fn () => $start('site.xml', fixtureReaderKey()))->toThrow(ValidationException::class)
        ->and(fn () => $start('site.json', 'reader:gone/fake-export'))->toThrow(ValidationException::class);
});

it('offers no reader once its plugin is off', function (): void {
    readersEnabled();
    expect(app(ImportReaders::class)->forFileName('site.json'))->not->toBeNull();

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/readers', (string) $this->admin->id));
    app()->forgetInstance(ImportReaders::class);

    expect(app(ImportReaders::class)->all())->toBe([]);
});

it('imports a reader’s comments onto their posts, with a spam comment kept as spam', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'comment', 'id' => 'c1', 'postId' => 'i1', 'status' => 'published', 'content' => 'Nice one', 'authorName' => 'Sam', 'authorEmail' => 'sam@example.com', 'publishedAt' => '2026-01-03 10:00:00'];
    $export['items'][] = ['kind' => 'comment', 'id' => 'c2', 'postId' => 'i1', 'status' => 'spam', 'content' => 'Buy now', 'authorName' => 'Bot', 'authorEmail' => 'bot@example.com', 'publishedAt' => '2026-01-03 11:00:00'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);
    FileImports::run($file->refresh());

    $hello = DB::table('contents')->where('title', 'Hello')->value('id');
    $comments = DB::table('comments')->where('content_id', $hello)->orderBy('body')->get(['body', 'author_name', 'status']);

    expect($comments->map(fn ($row) => [json_decode($row->body, true)['content'][0]['content'][0]['text'], $row->author_name, $row->status])->all())
        ->toBe([['Buy now', 'Bot', 'spam'], ['Nice one', 'Sam', 'approved']]);
});

it('imports a reader’s menus with their items, each item pointing at its post and its parent item', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'menu', 'id' => 'm1', 'title' => 'Main', 'slug' => 'main'];
    $export['items'][] = ['kind' => 'menu-item', 'id' => 'mi1', 'menuId' => 'm1', 'itemType' => 'content', 'objectId' => 'i1', 'order' => 1, 'title' => 'Hello'];
    $export['items'][] = ['kind' => 'menu-item', 'id' => 'mi2', 'menuId' => 'm1', 'itemType' => 'link', 'url' => 'https://example.com/x', 'order' => 2, 'title' => 'Example', 'parentId' => 'mi1'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);
    FileImports::run($file->refresh());

    $navigation = DB::table('navigations')->where('name', 'Main')->first();
    $hello = DB::table('contents')->where('title', 'Hello')->value('id');
    $items = DB::table('navigation_items')->where('navigation_id', $navigation->id)->orderBy('position')->get(['id', 'label', 'kind', 'target_id', 'url', 'parent_id']);

    $byLabel = $items->keyBy('label');

    expect($navigation)->not->toBeNull()
        ->and($items->count())->toBe(2)
        ->and($byLabel['Hello']->target_id)->toBe($hello)
        ->and($byLabel['Example']->url)->toBe('https://example.com/x')
        ->and($byLabel['Example']->parent_id)->toBe($byLabel['Hello']->id);
});

it('keeps each reader item’s identity when a re-import reorders the file and adds one', function (): void {
    readersEnabled();

    $first = readerUploaded('site.json', json_encode(fakeSiteExport(), JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($first);
    FileImports::run($first->refresh());

    $helloId = DB::table('contents')->where('title', 'Hello')->value('id');
    $aboutId = DB::table('contents')->where('title', 'About')->value('id');

    $export = fakeSiteExport();
    $export['items'] = array_reverse($export['items']);
    $export['items'][] = ['kind' => 'post', 'id' => 'i3', 'title' => 'Second', 'slug' => 'second', 'status' => 'published', 'content' => '<p>New</p>'];

    $again = readerUploaded('site-again.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($again);
    FileImports::run($again->refresh());

    expect(DB::table('contents')->where('title', 'Hello')->value('id'))->toBe($helloId)
        ->and(DB::table('contents')->where('title', 'About')->value('id'))->toBe($aboutId)
        ->and(DB::table('contents')->where('title', 'Second')->count())->toBe(1)
        ->and(DB::table('contents')->whereIn('title', ['Hello', 'About', 'Second'])->count())->toBe(3);
});

it('refuses a reader’s file for a site already imported from another system', function (): void {
    readersEnabled();
    ImportSource::query()->create(['address' => 'https://old.example.com', 'created_by' => $this->admin->id, 'kind' => 'wordpress']);

    $file = readerUploaded('site.json', json_encode(fakeSiteExport(), JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->state)->toBe('refused')
        ->and($file->refused)->toBe('This site was imported from another system, so this file can’t join its import.');
});

it('refuses a menu item type a reader’s file doesn’t know, in the neutral words', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'menu', 'id' => 'm1', 'title' => 'Main', 'slug' => 'main'];
    $export['items'][] = ['kind' => 'menu-item', 'id' => 'mi1', 'menuId' => 'm1', 'itemType' => 'post_type', 'objectId' => 'i1', 'order' => 1, 'title' => 'Hello'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->state)->toBe('refused')
        ->and($file->refused)->toBe('A menu item of this file has a type Teme doesn’t know.');
});

it('refuses a post status a reader’s file doesn’t know, instead of making the post a draft', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'post', 'id' => 'i9', 'title' => 'Odd', 'slug' => 'odd', 'status' => 'publish', 'content' => '<p>Odd</p>'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), readerKeyOf('raw'));
    readerReadToEnd($file);

    expect($file->refresh()->state)->toBe('refused')
        ->and($file->refused)->toBe('A post or page of this file has a status Teme doesn’t know.')
        ->and(DB::table('contents')->where('title', 'Odd')->exists())->toBeFalse();
});

it('refuses a comment status a reader’s file doesn’t know', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'comment', 'id' => 'c9', 'postId' => 'i1', 'status' => 'approved', 'content' => 'Odd', 'authorName' => 'Sam'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), readerKeyOf('raw'));
    readerReadToEnd($file);

    expect($file->refresh()->state)->toBe('refused')
        ->and($file->refused)->toBe('A comment of this file has a status Teme doesn’t know.');
});

it('counts, in one note, the items whose parent isn’t in a reader’s file', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'post', 'id' => 'i8', 'title' => 'Child', 'slug' => 'child', 'status' => 'published', 'content' => '<p>Child</p>', 'parentId' => 'gone'];
    $export['items'][] = ['kind' => 'term', 'id' => 't8', 'taxonomy' => 'category', 'name' => 'Sub', 'slug' => 'sub', 'parentId' => 'gone'];
    $export['items'][] = ['kind' => 'comment', 'id' => 'c8', 'postId' => 'i1', 'status' => 'published', 'content' => 'Reply', 'authorName' => 'Sam', 'parentId' => 'gone'];
    $export['items'][] = ['kind' => 'menu', 'id' => 'm8', 'title' => 'Main', 'slug' => 'main'];
    $export['items'][] = ['kind' => 'menu-item', 'id' => 'mi8', 'menuId' => 'm8', 'itemType' => 'link', 'url' => 'https://example.com/', 'title' => 'Out', 'parentId' => 'gone'];
    $export['items'][] = ['kind' => 'media', 'id' => 'a8', 'title' => 'Pic', 'url' => 'https://old.example.com/pic.jpg', 'parentId' => 'gone'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), readerKeyOf('raw'));
    readerReadToEnd($file);

    $note = $file->refresh()->report['notes']['parent'] ?? null;

    expect($note)->not->toBeNull()
        ->and($note['count'])->toBe(5)
        ->and($note['examples'])->toBe(['i8', 't8', 'c8', 'mi8', 'a8'])
        ->and($note['sentence'])->toContain('placed at the top');
});

it('counts no parent note when every parent is in a reader’s file', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'post', 'id' => 'i8', 'title' => 'Child', 'slug' => 'child', 'status' => 'published', 'content' => '<p>Child</p>', 'parentId' => 'i1'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), readerKeyOf('raw'));
    readerReadToEnd($file);

    expect($file->refresh()->report['notes'] ?? [])->not->toHaveKey('parent');
});

it('imports a reader’s tag in the engine’s taxonomy word, and a menu item of type content', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'term', 'id' => 't2', 'taxonomy' => 'tag', 'name' => 'Tips', 'slug' => 'tips'];
    $export['items'][] = ['kind' => 'menu', 'id' => 'm1', 'title' => 'Main', 'slug' => 'main'];
    $export['items'][] = ['kind' => 'menu-item', 'id' => 'mi1', 'menuId' => 'm1', 'itemType' => 'content', 'objectId' => 'i1', 'order' => 1, 'title' => 'Hello'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->state)->toBe('ready')
        ->and(DB::table('import_file_rows')->where('file_id', $file->id)->whereRaw("payload->>'taxonomy' = 'post_tag'")->exists())->toBeTrue()
        ->and(DB::table('import_file_rows')->where('file_id', $file->id)->whereRaw("payload->>'type' = 'post_type'")->exists())->toBeTrue();
});

it('carries a reader’s excerpt, sticky flag, featured picture, menu order, term parent and media parent and author', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'term', 'id' => 't2', 'taxonomy' => 'category', 'name' => 'Local', 'slug' => 'local', 'parentId' => 't1'];
    $export['items'][3]['excerpt'] = 'A short summary';
    $export['items'][3]['sticky'] = true;
    $export['items'][3]['featuredMediaId'] = 'm1';
    $export['items'][3]['order'] = 4;
    $export['items'][2]['parentId'] = 'i1';
    $export['items'][2]['authorId'] = 'p1';

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    $post = DB::table('import_file_rows')->where('file_id', $file->id)->where('list', 'content')->whereRaw("payload->>'title' = 'Hello'")->value('payload');
    $media = DB::table('import_file_rows')->where('file_id', $file->id)->where('list', 'media')->value('payload');
    $local = DB::table('import_file_rows')->where('file_id', $file->id)->whereRaw("payload->>'name' = 'Local'")->value('payload');
    $post = json_decode((string) $post, true);
    $media = json_decode((string) $media, true);
    $local = json_decode((string) $local, true);

    expect($file->refresh()->state)->toBe('ready')
        ->and($post['excerpt'])->toBe('A short summary')
        ->and($post['sticky'])->toBeTrue()
        ->and($post['featured_media'])->toBe($media['id'])
        ->and($post['menu_order'])->toBe(4)
        ->and($local['parent'])->toBe(DB::table('import_file_rows')->where('file_id', $file->id)->whereRaw("payload->>'name' = 'News'")->value(DB::raw("(payload->>'id')::bigint")))
        ->and($media['parent'])->toBe($post['id'])
        ->and($media['author'])->toBe(DB::table('import_file_rows')->where('file_id', $file->id)->where('list', 'users')->value(DB::raw("(payload->>'id')::bigint")));
});

it('lists the import readers on Tools › Import, for the upload to choose one', function (): void {
    readersEnabled();

    $this->actingAs($this->admin)->get(route('tools.importer.index'))
        ->assertInertia(fn ($page) => $page->has('readers', 4)
            ->where('readers.0.key', fixtureReaderKey())
            ->where('readers.0.label', 'Fake export')
            ->where('readers.0.fileTypes', ['json']));
});

/**
 * The key an upload names for the fixture reader with the given reader ID.
 */
function readerKeyOf(string $readerId): string
{
    foreach (app(ImportReaders::class)->all() as $offer) {
        if ($offer->readerId === $readerId) {
            return $offer->key();
        }
    }

    throw new RuntimeException("No reader {$readerId}.");
}

it('refuses a reader that hands back a cursor it has already been given', function (): void {
    readersEnabled();

    Queue::fake();
    $file = readerUploaded('site.json', json_encode(fakeSiteExport(), JSON_THROW_ON_ERROR), readerKeyOf('stuck'));
    readerReadToEnd($file);

    expect($file->refresh()->state)->toBe('refused')
        ->and($file->refused)->toBe('The import reader repeated a page of this file, so Teme stopped reading it.');
});

it('keeps what a slow reader has read and finishes in later parts, rather than refusing the file', function (): void {
    readersEnabled();
    config(['teme.import.reader_page_size' => 2]);

    $export = fakeSiteExport();

    for ($i = 0; $i < 4; $i++) {
        $export['items'][] = ['kind' => 'post', 'id' => 'extra-'.$i, 'title' => 'Extra '.$i, 'slug' => 'extra-'.$i, 'status' => 'published', 'content' => '<p>x</p>'];
    }

    Queue::fake();
    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), readerKeyOf('slow'));

    expect($file->refresh()->state)->toBe('reading');

    Queue::fake();
    (new ReadImportFile($file->id, JobActor::person($file->uploaded_by)))->handle();

    expect($file->refresh()->state)->toBe('reading')
        ->and(Queue::pushedJobs()[ReadImportFile::class] ?? [])->not->toBeEmpty();

    $parts = readerReadToEnd($file);

    expect($parts)->toBeGreaterThan(0)
        ->and($file->refresh()->state)->toBe('ready')
        ->and(DB::table('import_file_rows')->where('file_id', $file->id)->where('list', 'content')->count())->toBe(6);
});

it('imports the sample export twice, and the second import changes nothing', function (): void {
    readersEnabled();
    readerPicturesFake();

    $bytes = (string) file_get_contents(base_path('packages/teme-testing/samples/fake-export.json'));
    FileImports::run(readerUploaded('sample.json', $bytes, fixtureReaderKey())->refresh());

    $contents = DB::table('contents')->orderBy('id')->get(['id', 'title', 'status'])->all();
    $terms = DB::table('terms')->orderBy('id')->get(['id', 'name'])->all();
    $people = DB::table('users')->orderBy('id')->get(['id', 'email'])->all();
    $mapped = DB::table('import_map')->count();
    $media = DB::table('media')->orderBy('id')->get(['id', 'file_version'])->all();

    FileImports::run(readerUploaded('sample-again.json', $bytes, fixtureReaderKey())->refresh());

    expect(DB::table('contents')->orderBy('id')->get(['id', 'title', 'status'])->all())->toEqual($contents)
        ->and(DB::table('terms')->orderBy('id')->get(['id', 'name'])->all())->toEqual($terms)
        ->and(DB::table('users')->orderBy('id')->get(['id', 'email'])->all())->toEqual($people)
        ->and(DB::table('import_map')->count())->toBe($mapped)
        ->and(DB::table('media')->orderBy('id')->get(['id', 'file_version'])->all())->toEqual($media)
        ->and(count($contents))->toBe(2)
        ->and(count($terms))->toBe(1)
        ->and(count($media))->toBeGreaterThan(0);
});

it('names the import reader behind a site imported from a reader’s file, on Tools › Import', function (): void {
    readersEnabled();
    FileImports::run(readerUploaded('site.json', json_encode(fakeSiteExport(), JSON_THROW_ON_ERROR), fixtureReaderKey())->refresh());

    $source = (string) ImportSource::query()->where('kind', fixtureReaderKey())->value('id');

    $this->actingAs($this->admin)->get(route('tools.importer.index'))
        ->assertInertia(fn ($page) => $page->where('sourceReaders', [$source => 'Fake export']));
});

it('accepts a reader’s file under the import upload cap, and refuses one over it', function (): void {
    readersEnabled();
    config(['teme.import.max_file_mb' => 2]);

    $start = fn (int $size): mixed => app(StartImportUpload::class)->execute(
        new StartImportUploadInput('site.json', $size, (string) $this->admin->id, fixtureReaderKey()),
    );

    expect($start(2 * 1024 * 1024))->not->toBeNull()
        ->and(fn () => $start(2 * 1024 * 1024 + 1))->toThrow(ValidationException::class);
});

it('keeps a reader’s old address as an old address that leads to the post it was imported as', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][3]['oldAddress'] = 'https://old.example.com/2020/hello/';

    FileImports::run(readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey())->refresh());

    $hello = (string) DB::table('contents')->where('title', 'Hello')->value('id');
    $old = DB::table('paths')->where('path', '2020/hello')->where('is_current', false)->first(['target_id']);

    expect($old)->not->toBeNull()
        ->and((string) $old->target_id)->toBe($hello);
});

/**
 * Lets an import download the fake export's picture: its host resolves to a public address,
 * and every request is answered with a small JPEG. Any other request fails the test.
 */
function readerPicturesFake(): void
{
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    importResolve(['old.example.com' => ['93.184.216.34']]);
    config(['teme.import.local_sources' => false]);
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']));
}

it('records the plan’s media choice on the run, so a run that brings pictures in a pass after the articles is told apart from one that downloads them with them', function (): void {
    readersEnabled();
    readerPicturesFake();

    $bytes = (string) file_get_contents(base_path('packages/teme-testing/samples/fake-export.json'));

    $later = FileImports::run(readerUploaded('later.json', $bytes, fixtureReaderKey())->refresh(), ['media' => 'later']);
    $passesAfterLater = ImportRun::query()->where('kind', ImportRunKind::MediaPass)->count();
    $download = FileImports::run(readerUploaded('download.json', $bytes, fixtureReaderKey())->refresh(), ['media' => 'download']);

    expect($later->choices['media'])->toBe('later')
        ->and($download->choices['media'])->toBe('download')
        ->and($passesAfterLater)->toBe(1)
        ->and(ImportRun::query()->where('kind', ImportRunKind::MediaPass)->count())->toBe(1)
        ->and(DB::table('media')->count())->toBeGreaterThan(0);
});

it('writes a report of what a reader’s file holds, when the file is read', function (): void {
    readersEnabled();

    $file = readerUploaded('sample.json', (string) file_get_contents(base_path('packages/teme-testing/samples/fake-export.json')), fixtureReaderKey());
    readerReadToEnd($file);
    $report = $file->refresh()->report;

    expect($report['report_version'])->toBe(1)
        ->and($report['errors'])->toBe([])
        ->and($report['sections']['users']['total'])->toBe(1)
        ->and($report['sections']['media']['total'])->toBe(1)
        ->and($report['sections']['comments']['total'])->toBe(0);
});

it('imports one site once, whether its export names its address with a trailing slash or without', function (): void {
    readersEnabled();

    FileImports::run(readerUploaded('site.json', json_encode(fakeSiteExport(), JSON_THROW_ON_ERROR), fixtureReaderKey())->refresh());

    $withSlash = fakeSiteExport();
    $withSlash['site'] = 'https://old.example.com/';
    FileImports::run(readerUploaded('site-slash.json', json_encode($withSlash, JSON_THROW_ON_ERROR), fixtureReaderKey())->refresh());

    expect(DB::table('contents')->whereIn('title', ['Hello', 'About'])->count())->toBe(2)
        ->and(DB::table('import_sources')->where('kind', fixtureReaderKey())->count())->toBe(1)
        ->and(DB::table('import_reader_ids')->count())->toBe(5);
});

it('refuses to connect WordPress to a site a reader’s file already imported from another system', function (): void {
    readersEnabled();
    importFake();

    $export = fakeSiteExport();
    $export['site'] = FakeCompanion::SITE;
    FileImports::run(readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey())->refresh());

    expect(fn () => app(ConnectImportSource::class)->execute(new ConnectImportSourceInput(FakeCompanion::SITE, FakeCompanion::USER, FakeCompanion::PASSWORD, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'This site was imported from another system, so it can’t be connected to WordPress here.');
});

it('refuses a reader’s term in a taxonomy Teme keeps for itself, or doesn’t have', function (string $taxonomy): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'term', 'id' => 't9', 'taxonomy' => $taxonomy, 'name' => 'Odd', 'slug' => 'odd'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->state)->toBe('refused')
        ->and($file->refused)->toBe('A term of this file has a taxonomy Teme doesn’t know.');
})->with(['post_tag', 'language', 'nav_menu', 'no-such-taxonomy']);

it('gives reader IDs to no one, and writes nothing, when the person may not import', function (): void {
    readersEnabled();
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(AssignImportReaderIds::class)->execute(new AssignImportReaderIdsInput('https://old.example.com', fixtureReaderKey(), [['kind' => 'post', 'id' => 'i1']], (string) $editor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('import_reader_ids')->count())->toBe(0);
});

it('imports a reader’s person with no login as an author, keeps their posts, and makes them once', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'person', 'id' => 'p2', 'displayName' => 'Guest Writer', 'email' => 'guest@example.com'];
    $export['items'][3]['authorId'] = 'p2';

    FileImports::run(readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey())->refresh());
    FileImports::run(readerUploaded('site-again.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey())->refresh());

    $guest = DB::table('users')->where('email', 'guest@example.com')->first();

    expect($guest)->not->toBeNull()
        ->and(DB::table('users')->where('email', 'guest@example.com')->count())->toBe(1)
        ->and(DB::table('contents')->where('title', 'Hello')->value('author_id'))->toBe($guest->id);
});

it('counts and names a reader’s comments whose post isn’t in the file, in the report', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'comment', 'id' => 'c7', 'postId' => 'gone', 'status' => 'published', 'content' => 'Lost', 'authorName' => 'Sam'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->report['notes']['comment']['count'])->toBe(1)
        ->and($file->report['notes']['comment']['examples'])->toBe(['c7'])
        ->and($file->report['notes']['comment']['sentence'])->toContain('aren’t in this file');
});

it('counts and names a reader’s menu items whose menu isn’t in the file, in the report', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'menu-item', 'id' => 'mi9', 'menuId' => 'nope', 'itemType' => 'content', 'objectId' => 'i1', 'order' => 1, 'title' => 'Hello'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->report['notes']['menu-item']['count'])->toBe(1)
        ->and($file->report['notes']['menu-item']['examples'])->toBe(['mi9']);
});

it('says on the plan that a reader’s other languages gain an address prefix', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['languages'] = ['en', 'fr'];
    $export['defaultLanguage'] = 'en';
    $export['items'][3]['language'] = 'en';

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->report['notes']['language-prefix']['count'])->toBe(1)
        ->and($file->report['notes']['language-prefix']['sentence'])->toContain('first part of the address')
        ->and($file->report['notes'])->not->toHaveKey('language');
});

it('carries a reader’s media caption and description', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][2]['caption'] = 'A harbour at dusk';
    $export['items'][2]['description'] = 'Taken in January';

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    $media = json_decode((string) DB::table('import_file_rows')->where('file_id', $file->id)->where('list', 'media')->value('payload'), true);

    expect($media['caption'])->toBe('A harbour at dusk')
        ->and($media['description'])->toBe('Taken in January');
});

it('stores a reader’s upload without the .xml of a WordPress export', function (): void {
    readersEnabled();

    $file = readerUploaded('site.json', json_encode(fakeSiteExport(), JSON_THROW_ON_ERROR), fixtureReaderKey());

    expect($file->path())->not->toEndWith('.xml')
        ->and(Storage::disk('local')->exists($file->path()))->toBeTrue();
});

it('never makes up a login another person of the file has, so a guest is not merged into a real person', function (): void {
    readersEnabled();

    $export = [
        'system' => 'Fake export',
        'version' => '2',
        'site' => 'https://old.example.com',
        'languages' => ['en'],
        'items' => [
            ['kind' => 'person', 'id' => 'p2', 'displayName' => 'Ada', 'email' => 'guest@example.com'],
            ['kind' => 'person', 'id' => 'p3', 'login' => 'ada', 'email' => 'real@example.com', 'displayName' => 'Ada Real'],
            ['kind' => 'post', 'id' => 'i1', 'title' => 'Guest post', 'slug' => 'guest', 'status' => 'published', 'content' => '<p>Guest</p>', 'authorId' => 'p2'],
            ['kind' => 'post', 'id' => 'i2', 'title' => 'Real post', 'slug' => 'real', 'status' => 'published', 'content' => '<p>Real</p>', 'authorId' => 'p3'],
        ],
    ];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);
    FileImports::run($file->refresh());

    $guest = DB::table('users')->where('email', 'guest@example.com')->first();
    $real = DB::table('users')->where('email', 'real@example.com')->first();

    expect($guest)->not->toBeNull()
        ->and($real)->not->toBeNull()
        ->and($guest->id)->not->toBe($real->id)
        ->and(DB::table('contents')->where('title', 'Guest post')->value('author_id'))->toBe($guest->id)
        ->and(DB::table('contents')->where('title', 'Real post')->value('author_id'))->toBe($real->id);
});

it('imports a reader’s term in a taxonomy a plugin registered with Teme, under that taxonomy, and files a post under it where its type has it', function (): void {
    readersEnabled();
    app(TaxonomyRegistry::class)->register(new Taxonomy('topic', 'Topic', 'Topics', 'topics'));
    $types = app(ContentTypeRegistry::class);
    // The registry has no way to change a type that is registered, so the test sets post's taxonomies from inside it.
    $withTopic = $types->get('post')->withTaxonomies(['category', 'tag', 'topic']);
    (fn () => $this->types['post'] = $withTopic)->call($types);

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'term', 'id' => 't9', 'taxonomy' => 'topic', 'name' => 'Climate', 'slug' => 'climate'];
    $export['items'][3]['termIds'] = ['t1', 't9'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);
    $run = FileImports::run($file->refresh());
    $term = DB::table('terms')->where('taxonomy', 'topic')->where('name', 'Climate')->first();
    $post = DB::table('contents')->where('title', 'Hello')->first();

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($term)->not->toBeNull()
        ->and($post)->not->toBeNull()
        ->and(DB::table('content_terms')->where('content_id', $post->id)->where('term_id', $term->id)->exists())->toBeTrue();
});

it('says in plain words which system a reader’s file came from, when a person in it has no email address', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'person', 'id' => 'p5', 'displayName' => 'No Mail'];
    $export['items'][3]['authorId'] = 'p5';

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);
    $run = FileImports::run($file->refresh());

    $messages = DB::table('import_messages')->where('run_id', $run->id)->where('code', 'user.no-email')->pluck('message')->all();

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('Fake export')
        ->and($messages[0])->not->toContain('WordPress');
});

it('counts and names a reader’s posts that name a term the file doesn’t have, in the report', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][3]['termIds'] = ['t1', 'gone'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->report['notes']['term']['count'])->toBe(1)
        ->and($file->report['notes']['term']['examples'])->toBe(['i1'])
        ->and($file->report['notes']['term']['sentence'])->toContain('aren’t in this file');
});

it('counts and names a reader’s posts whose featured picture isn’t in the file, in the report', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][3]['featuredMediaId'] = 'gone-picture';

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->report['notes']['featured-image']['count'])->toBe(1)
        ->and($file->report['notes']['featured-image']['examples'])->toBe(['i1']);
});

it('counts and names a reader’s menu items that point at a post or term the file doesn’t have, in the report', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][] = ['kind' => 'menu', 'id' => 'm5', 'title' => 'Main', 'slug' => 'main'];
    $export['items'][] = ['kind' => 'menu-item', 'id' => 'mi4', 'menuId' => 'm5', 'itemType' => 'content', 'objectId' => 'gone', 'order' => 1, 'title' => 'Gone'];
    $export['items'][] = ['kind' => 'menu-item', 'id' => 'mi5', 'menuId' => 'm5', 'itemType' => 'term', 'objectId' => 't1', 'order' => 2, 'title' => 'News'];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->report['notes']['menu-target']['count'])->toBe(1)
        ->and($file->report['notes']['menu-target']['examples'])->toBe(['mi4']);
});

it('shows a reader file’s notes in the run’s report, as warnings, in the screen’s props', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][3]['termIds'] = ['t1', 'gone'];
    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);
    FileImports::run($file->refresh());

    $response = $this->actingAs($this->admin)->get('/admin/tools/import/'.$file->refresh()->source_id);
    $messages = collect($response->viewData('page')['props']['report']['messages']);
    $note = $messages->firstWhere('group', 'Terms not brought');

    expect($note)->not->toBeNull()
        ->and($note['level'])->toBe('warning')
        ->and($note['count'])->toBe(1)
        ->and($note['words'])->toContain('aren’t in this file')
        ->and($note['words'])->toContain('i1');
});

it('shows a reader file’s notes on its plan, in the plan’s props', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['items'][3]['termIds'] = ['t1', 'gone'];
    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    $version = (string) app(Middleware::class)->version(request());
    $response = $this->actingAs($this->admin)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'tools/import/show', 'X-Inertia-Partial-Data' => 'plan'])
        ->get('/admin/tools/import/'.$file->refresh()->source_id);

    $notes = collect($response->json('props.plan.fileForm.notes') ?? []);

    expect($notes->pluck('kind')->all())->toContain('term')
        ->and($notes->firstWhere('kind', 'term')['count'])->toBe(1);
});

/**
 * A fake export whose items span more than one read page when the page size is small.
 *
 * @param  list<array<string, mixed>>  $extra
 *
 * @return array<string, mixed>
 */
function multiPageExport(array $extra = []): array
{
    $export = fakeSiteExport();

    for ($i = 0; $i < 6; $i++) {
        $export['items'][] = ['kind' => 'post', 'id' => 'fill-'.$i, 'title' => 'Fill '.$i, 'slug' => 'fill-'.$i, 'status' => 'published', 'content' => '<p>f</p>'];
    }

    array_push($export['items'], ...$extra);

    return $export;
}

it('imports a reader’s multi-page file whole', function (): void {
    readersEnabled();
    config(['teme.import.reader_page_size' => 2]);

    $export = multiPageExport([
        ['kind' => 'post', 'id' => 'last', 'title' => 'Last', 'slug' => 'last', 'status' => 'published', 'content' => '<p>Last</p>'],
    ]);
    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    expect($file->refresh()->state)->toBe('ready')
        ->and($file->read_items)->toBe(count($export['items']))
        ->and(DB::table('import_file_rows')->where('file_id', $file->id)->where('list', 'content')->count())->toBe(9)
        ->and(DB::table('import_file_rows')->where('file_id', $file->id)->whereRaw("payload->>'title' = 'Last'")->exists())->toBeTrue();
});

it('resolves a parent that sits on a later page of a reader’s file', function (): void {
    readersEnabled();
    config(['teme.import.reader_page_size' => 2]);

    $export = [
        'system' => 'Fake export',
        'version' => '2',
        'site' => 'https://old.example.com',
        'languages' => ['en'],
        'items' => [
            ['kind' => 'page', 'id' => 'child', 'title' => 'Child', 'slug' => 'child', 'status' => 'published', 'content' => '<p>Child</p>', 'parentId' => 'parent-later'],
            ['kind' => 'post', 'id' => 'fill-1', 'title' => 'Fill 1', 'slug' => 'fill-1', 'status' => 'published', 'content' => '<p>f</p>'],
            ['kind' => 'post', 'id' => 'fill-2', 'title' => 'Fill 2', 'slug' => 'fill-2', 'status' => 'published', 'content' => '<p>f</p>'],
            ['kind' => 'page', 'id' => 'parent-later', 'title' => 'Parent', 'slug' => 'parent', 'status' => 'published', 'content' => '<p>Parent</p>'],
        ],
    ];

    $file = readerUploaded('site.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);

    $child = json_decode((string) DB::table('import_file_rows')->where('file_id', $file->id)->whereRaw("payload->>'title' = 'Child'")->value('payload'), true);
    $parent = json_decode((string) DB::table('import_file_rows')->where('file_id', $file->id)->whereRaw("payload->>'title' = 'Parent'")->value('payload'), true);

    expect($file->refresh()->state)->toBe('ready')
        ->and($child['parent'])->toBe($parent['id'])
        ->and($child['parent'])->not->toBe(0);
});

it('imports a reader’s multi-page file a second time and changes nothing', function (): void {
    readersEnabled();
    config(['teme.import.reader_page_size' => 2]);

    $bytes = json_encode(multiPageExport(), JSON_THROW_ON_ERROR);
    FileImports::run(readerUploaded('once.json', $bytes, fixtureReaderKey())->refresh());

    $contents = DB::table('contents')->orderBy('id')->get(['id', 'title', 'status'])->all();
    $mapped = DB::table('import_map')->count();

    FileImports::run(readerUploaded('twice.json', $bytes, fixtureReaderKey())->refresh());

    expect(DB::table('contents')->orderBy('id')->get(['id', 'title', 'status'])->all())->toEqual($contents)
        ->and(DB::table('import_map')->count())->toBe($mapped);
});

it('keeps memory flat while reading a reader’s file of many pages', function (): void {
    readersEnabled();
    config(['teme.import.reader_page_size' => 2]);

    $export = fakeSiteExport();

    for ($i = 0; $i < 40; $i++) {
        $export['items'][] = ['kind' => 'post', 'id' => 'm-'.$i, 'title' => 'M '.$i, 'slug' => 'm-'.$i, 'status' => 'published', 'content' => '<p>m</p>'];
    }

    $file = readerUploaded('many.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    $before = memory_get_peak_usage(true);
    readerReadToEnd($file);
    $used = memory_get_peak_usage(true) - $before;

    expect($file->refresh()->state)->toBe('ready')
        ->and($file->read_items)->toBe(count($export['items']))
        ->and($used)->toBeLessThan(16 * 1024 * 1024);
});

it('imports a reader’s two-language sample: adds the missing language, keeps each item’s language, and links translations', function (): void {
    readersEnabled();

    $bytes = (string) file_get_contents(base_path('packages/teme-testing/samples/fake-export-languages.json'));
    $file = readerUploaded('langs.json', $bytes, fixtureReaderKey());
    readerReadToEnd($file);
    $run = readerImport($file->refresh());

    $hello = DB::table('contents')->where('title', 'Hello')->first();
    $bonjour = DB::table('contents')->where('title', 'Bonjour')->first();
    $news = DB::table('terms')->where('slug', 'news')->first();
    $actualites = DB::table('terms')->where('slug', 'actualites')->first();
    $languages = app(SiteLanguages::class);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($languages->others())->toContain('fr')
        ->and($languages->prefix('fr'))->toBe('fr')
        ->and($hello?->language)->toBeNull()
        ->and($bonjour?->language)->toBe('fr')
        ->and($hello?->translation_group)->not->toBeNull()
        ->and($bonjour?->translation_group)->toBe($hello?->translation_group)
        ->and($news?->language)->toBeNull()
        ->and($actualites?->language)->toBe('fr')
        ->and($actualites?->translation_group)->toBe($news?->translation_group)
        ->and(addrGet('/fr/bonjour/')->getStatusCode())->toBe(200);
});

it('imports a reader item whose language Teme doesn’t offer in the site’s default language, and says so', function (): void {
    readersEnabled();

    $export = fakeSiteExport();
    $export['languages'] = ['en', 'xx'];
    $export['defaultLanguage'] = 'en';
    $export['items'][3]['language'] = 'xx';
    $export['items'][3]['title'] = 'Unknown tongue';

    $file = readerUploaded('unknown.json', json_encode($export, JSON_THROW_ON_ERROR), fixtureReaderKey());
    readerReadToEnd($file);
    $run = readerImport($file->refresh());

    $post = DB::table('contents')->where('title', 'Unknown tongue')->first();
    $codes = ImportMessageRecord::query()->where('run_id', $run->id)->pluck('code')->all();

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($post)->not->toBeNull()
        ->and($post->language)->toBeNull()
        ->and($codes)->toContain('post.language-unknown');
});
