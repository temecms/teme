<?php

declare(strict_types=1);

use App\Domain\Import\File\ContentBlocks;
use App\Domain\Import\File\Jobs\ReadImportFile;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\FinishImportUpload;
use App\Domain\Import\Operations\Input\FinishImportUploadInput;
use App\Domain\Import\Operations\Input\ReceiveImportPieceInput;
use App\Domain\Import\Operations\Input\StartImportUploadInput;
use App\Domain\Import\Operations\ReceiveImportPiece;
use App\Domain\Import\Operations\StartImportUpload;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Readers\ImportReaders;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ExportFiles;
use Tests\Fixtures\Import\FileImports;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once dirname(__DIR__, 2).'/Fixtures/plugins/readers/src/Readers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    Storage::fake('local');
    app()->instance(ContentBlocks::class, ExportFiles::blocks());
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
 * Reads the file to its end as its uploader.
 */
function readerReadToEnd(ImportFile $file): void
{
    (new ReadImportFile($file->id, JobActor::person($file->uploaded_by)))->handle();
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
        ->assertInertia(fn ($page) => $page->has('readers', 3)
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

    $file = readerUploaded('site.json', json_encode(fakeSiteExport(), JSON_THROW_ON_ERROR), readerKeyOf('stuck'));
    readerReadToEnd($file);

    expect($file->refresh()->state)->toBe('refused')
        ->and($file->refused)->toBe('The import reader repeated a page of this file, so Teme stopped reading it.');
});

it('refuses a file a reader takes longer than a read may take, rather than running out of time', function (): void {
    readersEnabled();

    $file = readerUploaded('site.json', json_encode(fakeSiteExport(), JSON_THROW_ON_ERROR), readerKeyOf('slow'));
    readerReadToEnd($file);
    Carbon::setTestNow();

    expect($file->refresh()->state)->toBe('refused')
        ->and($file->refused)->toBe('This file took too long to read in one go, so Teme couldn’t finish reading it.');
});

it('imports the sample export twice, and the second import changes nothing', function (): void {
    readersEnabled();

    $bytes = (string) file_get_contents(base_path('packages/teme-testing/samples/fake-export.json'));
    FileImports::run(readerUploaded('sample.json', $bytes, fixtureReaderKey())->refresh());

    $contents = DB::table('contents')->orderBy('id')->get(['id', 'title', 'status'])->all();
    $mapped = DB::table('import_map')->count();

    FileImports::run(readerUploaded('sample-again.json', $bytes, fixtureReaderKey())->refresh());

    expect(DB::table('contents')->orderBy('id')->get(['id', 'title', 'status'])->all())->toEqual($contents)
        ->and(DB::table('import_map')->count())->toBe($mapped)
        ->and(count($contents))->toBe(2);
});

it('names the import reader behind a site imported from a reader’s file, on Tools › Import', function (): void {
    readersEnabled();
    FileImports::run(readerUploaded('site.json', json_encode(fakeSiteExport(), JSON_THROW_ON_ERROR), fixtureReaderKey())->refresh());

    $source = (string) ImportSource::query()->where('kind', fixtureReaderKey())->value('id');

    $this->actingAs($this->admin)->get(route('tools.importer.index'))
        ->assertInertia(fn ($page) => $page->where('sourceReaders', [$source => 'Fake export']));
});

it('refuses a reader’s file too large for a reader to read in one go, when it starts', function (): void {
    readersEnabled();

    $start = fn (int $size): mixed => app(StartImportUpload::class)->execute(
        new StartImportUploadInput('site.json', $size, (string) $this->admin->id, fixtureReaderKey()),
    );

    expect(fn () => $start(ReadImportFile::READER_BYTES + 1))->toThrow(ValidationException::class)
        ->and($start(ReadImportFile::READER_BYTES))->not->toBeNull();
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

it('records the plan’s choices on the run a reader’s file starts', function (): void {
    readersEnabled();

    $run = FileImports::run(readerUploaded('sample.json', (string) file_get_contents(base_path('packages/teme-testing/samples/fake-export.json')), fixtureReaderKey())->refresh(), ['outside_images' => ['cdn.example.com']]);

    expect($run->choices['outside_images'])->toBe(['cdn.example.com']);
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
