<?php

declare(strict_types=1);

use App\Domain\Import\File\ContentBlocks;
use App\Domain\Import\File\Jobs\ReadImportFile;
use App\Domain\Import\Models\ImportFile;
use App\Domain\Import\Operations\FinishImportUpload;
use App\Domain\Import\Operations\Input\FinishImportUploadInput;
use App\Domain\Import\Operations\Input\ReceiveImportPieceInput;
use App\Domain\Import\Operations\Input\StartImportUploadInput;
use App\Domain\Import\Operations\ReceiveImportPiece;
use App\Domain\Import\Operations\StartImportUpload;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Readers\ImportReaders;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ExportFiles;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once dirname(__DIR__).'/Fixtures/plugins/readers/src/Readers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (AccountNotificationsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ned Admin'])->assignRole('administrator');
});

/**
 * Enables the readers fixture, which reads the fake export, agreeing to what it asks.
 */
function pickerReadersEnabled(): void
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

it('offers the reader beside WordPress on the upload, and the reader’s file type once it is chosen', function (): void {
    pickerReadersEnabled();

    cmSignIn($this->admin)
        ->navigate('/admin/tools/import')
        ->press('Upload a file')
        ->assertSee('Read the file with')
        ->press('[aria-label="Read the file with"]')
        ->press('Fake export')
        ->assertSee('The file .json that Fake export reads');
});

it('shows a reader file’s note on its plan, where the person decides before the run', function (): void {
    pickerReadersEnabled();
    Storage::fake('local');
    app()->instance(ContentBlocks::class, ExportFiles::blocks());

    $export = [
        'system' => 'Fake export',
        'version' => '2',
        'site' => 'https://old.example.com',
        'languages' => ['en'],
        'items' => [
            ['kind' => 'term', 'id' => 't1', 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news'],
            ['kind' => 'post', 'id' => 'i1', 'title' => 'Hello', 'slug' => 'hello', 'status' => 'published', 'content' => '<p>Hi</p>', 'termIds' => ['t1', 'gone']],
        ],
    ];
    $actor = (string) $this->admin->id;
    $bytes = json_encode($export, JSON_THROW_ON_ERROR);
    $started = app(StartImportUpload::class)->execute(new StartImportUploadInput('site.json', strlen($bytes), $actor, app(ImportReaders::class)->all()[0]->key()));
    app(ReceiveImportPiece::class)->execute(new ReceiveImportPieceInput($started['fileId'], 0, $bytes, $actor));
    app(FinishImportUpload::class)->execute(new FinishImportUploadInput($started['fileId'], $actor));
    $file = ImportFile::query()->findOrFail($started['fileId']);
    (new ReadImportFile($file->id, JobActor::person($file->uploaded_by)))->handle();

    cmSignIn($this->admin)
        ->navigate('/admin/tools/import/'.$file->refresh()->source_id)
        ->assertSee('Not brought from this file')
        ->assertSee('Terms not brought');
});
