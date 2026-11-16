<?php

declare(strict_types=1);

use App\Console\Commands\RunInstaller;
use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Installer\InstallerRunner;
use App\Domain\Extensions\Installer\StageValidator;
use App\Domain\Extensions\Operations\AgreeToExtensionRequest;
use App\Domain\Extensions\Operations\Input\AgreeToExtensionRequestInput;
use App\Domain\Extensions\Operations\Input\RequestExtensionChangeInput;
use App\Domain\Extensions\Operations\Input\UploadExtensionInput;
use App\Domain\Extensions\Operations\RequestExtensionChange;
use App\Domain\Extensions\Operations\UploadExtension;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Queries\PluginsScreen;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Extensions\ExtensionsFolder;
use Tests\Fixtures\Plugins\PluginFixtures;

// The installer's three parts together (docs/plans/plugin-installer.md, "The join"): the admin's
// Operations record, `teme:installer --once` carries out, the Plugins screen shows what runs.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.version' => '0.5.0', 'teme.extensions.packagist' => false]);
    $this->admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new OperatorElevation);

    $this->folder = ExtensionsFolder::make();
    config(['teme.extensions.path' => $this->folder->root]);
    app()->instance(InstallerRunner::class, $this->folder->runner());

    /** The fixture package's folder as a ZIP an administrator uploads, inside one top folder. */
    $this->zip = function (string $fixture): string {
        $folder = ExtensionsFolder::PACKAGES.'/'.$fixture;
        $file = tempnam(sys_get_temp_dir(), 'teme-e2e-zip-');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $entry) {
            $zip->addFromString('hello-extension/'.str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($folder) + 1)), (string) file_get_contents($entry->getPathname()));
        }

        $zip->close();
        $bytes = (string) file_get_contents($file);
        unlink($file);

        return $bytes;
    };

    $this->upload = fn (string $fixture): string => app(UploadExtension::class)->execute(new UploadExtensionInput("{$fixture}.zip", ($this->zip)($fixture), (string) $this->admin->id))['id'];

    $this->ask = fn (ExtensionAction $action, ?string $upload = null): string => app(RequestExtensionChange::class)->execute(new RequestExtensionChangeInput(
        $action,
        ExtensionKind::Plugin,
        $action === ExtensionAction::Rollback ? null : 'fixtures/hello-extension',
        sourceKind: $upload === null ? null : 'upload',
        uploadId: $upload,
        actorId: (string) $this->admin->id,
    ));

    /** Runs the installer once, as the service would, and returns the request's row. */
    $this->install = function (string $id): object {
        $this->artisan('teme:installer', ['--once' => true])->assertExitCode(0);

        return DB::table('extension_requests')->where('id', $id)->first();
    };

    /** The version of the plugin the current release holds, from its lock, or null. */
    $this->runs = function (): ?string {
        $current = $this->folder->store->current();

        foreach ($current === null ? [] : StageValidator::locked($this->folder->store->path($current)) as $package) {
            if ($package['name'] === 'fixtures/hello-extension') {
                return (string) $package['version'];
            }
        }

        return null;
    };

    /** The Plugins screen's row, as a fresh process would see it with bootstrap/extensions.php loading the current release. */
    $this->row = function (): ?array {
        PluginFixtures::forget();
        $path = $this->folder->store->path((string) $this->folder->store->current()).'/vendor/fixtures/hello-extension';
        $version = ($this->runs)();
        PluginFixtures::installed($version === null ? [] : ['fixtures/hello-extension' => $path], $version === null ? [] : ['fixtures/hello-extension' => ['version' => $version]]);

        return collect(app(PluginsScreen::class)->rows())->firstWhere('name', 'fixtures/hello-extension');
    };
});

afterEach(function (): void {
    PluginFixtures::forget();
    $this->folder->delete();
    @unlink(RunInstaller::READY);
});

it('installs an uploaded plugin, enables it, updates it after agreement, rolls it back and removes it', function (): void {
    // Upload and ask; the installer carries it out; the plugin is listed, not enabled.
    $row = ($this->install)(($this->ask)(ExtensionAction::Install, ($this->upload)('hello-extension')));

    expect($row->status)->toBe('done', (string) $row->error)
        ->and(($this->runs)())->toBe('1.0.0')
        ->and(DB::table('extension_uploads')->count())->toBe(0)
        ->and(($this->row)())->toMatchArray(['version' => '1.0.0', 'enabled' => false, 'origin' => 'extensions']);

    // Enabling goes through the existing consent path.
    app(EnablePlugin::class)->execute(new EnablePluginInput('fixtures/hello-extension', '1.0.0', [], [], [], (string) $this->admin->id));
    expect(($this->row)())->toMatchArray(['enabled' => true]);

    // A table the plugin keeps, so its data outlives its code.
    Schema::create('hello_extension_notes', fn (Blueprint $table) => $table->id());
    DB::table('plugins')->where('name', 'fixtures/hello-extension')->update(['migrations' => json_encode(['2027_01_01_000000_notes' => ['tables' => ['hello_extension_notes']]])]);

    // An update by a second upload replaces the installed version, so it waits for agreement.
    $id = ($this->ask)(ExtensionAction::Update, ($this->upload)('hello-extension-1.1.0'));
    $row = ($this->install)($id);
    $needs = json_decode((string) $row->needs, true);

    expect($row->status)->toBe('waiting', (string) $row->error)
        ->and($needs)->toEqual(['replace' => ['package' => 'fixtures/hello-extension', 'from' => '1.0.0', 'to' => '1.1.0']])
        ->and(($this->runs)())->toBe('1.0.0');

    app(AgreeToExtensionRequest::class)->execute(new AgreeToExtensionRequestInput($id, $needs, (string) $this->admin->id));
    $row = ($this->install)($id);

    expect($row->status)->toBe('done', (string) $row->error)
        ->and(($this->runs)())->toBe('1.1.0')
        ->and(($this->row)())->toMatchArray(['version' => '1.1.0']);

    // Rollback returns 1.0.0.
    $row = ($this->install)(($this->ask)(ExtensionAction::Rollback));

    expect($row->status)->toBe('done', (string) $row->error)
        ->and(($this->runs)())->toBe('1.0.0');

    // Removal is refused while it is enabled, and goes through once it is disabled.
    expect(fn () => ($this->ask)(ExtensionAction::Remove))->toThrow(ValidationException::class, 'Disable fixtures/hello-extension first');

    app(DisablePlugin::class)->execute(new DisablePluginInput('fixtures/hello-extension', (string) $this->admin->id));
    $row = ($this->install)(($this->ask)(ExtensionAction::Remove));

    expect($row->status)->toBe('done', (string) $row->error)
        ->and(($this->runs)())->toBeNull();

    // Its code is gone, its data stays, and Remove its data… is offered.
    expect(($this->row)())->toMatchArray(['state' => 'code_gone', 'canRemoveData' => true]);
})->group('composer');
