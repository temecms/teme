<?php

declare(strict_types=1);

use App\Domain\Import\File\ContentBlocks;
use App\Domain\Import\File\Jobs\ReadImportFile;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Input\StartImportUploadInput;
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
 * An uploaded reader file, not yet read, holding the given bytes.
 */
function readerUploaded(string $name, string $bytes, string $reader): ImportFile
{
    $file = ImportFile::query()->create([
        'uploaded_by' => test()->admin->id,
        'name' => $name,
        'size_bytes' => strlen($bytes),
        'received_bytes' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'state' => 'reading',
        'reader' => $reader,
    ]);

    Storage::disk('local')->put($file->path(), $bytes);

    return $file;
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
    $export['items'][] = ['kind' => 'menu-item', 'id' => 'mi1', 'menuId' => 'm1', 'itemType' => 'post_type', 'objectId' => 'i1', 'order' => 1, 'title' => 'Hello'];
    $export['items'][] = ['kind' => 'menu-item', 'id' => 'mi2', 'menuId' => 'm1', 'itemType' => 'custom', 'url' => 'https://example.com/x', 'order' => 2, 'title' => 'Example', 'parentId' => 'mi1'];

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
