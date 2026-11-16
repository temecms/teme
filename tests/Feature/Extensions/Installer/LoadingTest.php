<?php

declare(strict_types=1);

use App\Domain\Extensions\ActiveRelease;
use App\Domain\Plugins\InstalledPackages;
use App\Domain\Themes\ThemeRegistry;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Extensions\ExtensionsFolder;

/*
 * bootstrap/extensions.php runs before Laravel, once a process, so each
 * case runs PHP in a child process against an extensions folder built here.
 */

beforeEach(function (): void {
    $this->folder = ExtensionsFolder::make();
    $this->pins = $this->folder->pinsFile();

    /**
     * What a fresh PHP process loads with this environment.
     *
     * @param  array<string, string>  $environment
     *
     * @return array<string, mixed>
     */
    $this->child = function (array $environment = []): array {
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            require 'bootstrap/extensions.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $loaders = array_keys(Composer\Autoload\ClassLoader::getRegisteredLoaders());
            echo json_encode([
                'release' => defined('TEME_EXTENSIONS_RELEASE') ? TEME_EXTENSIONS_RELEASE : null,
                'held' => defined('TEME_EXTENSIONS_HELD') ? TEME_EXTENSIONS_HELD : null,
                'composer' => array_values(array_unique(Composer\InstalledVersions::getInstalledPackagesByType('teme-plugin'))),
                'plugins' => array_keys(app(App\Domain\Plugins\PluginRegistry::class)->all()),
                'themes' => array_keys(app(App\Domain\Themes\ThemeRegistry::class)->all()),
                'loaders' => $loaders,
                'activeRelease' => (new ReflectionClass(App\Domain\Extensions\ActiveRelease::class))->getFileName(),
            ]);
            PHP;

        $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
            'TEME_EXTENSIONS_PATH' => $this->folder->root,
            'TEME_CORE_PINS' => $this->pins,
            'TEME_EXTENSIONS_ROLE' => false,
            ...$environment,
        ]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    };
});

afterEach(function (): void {
    $this->folder->delete();
});

it('lists a plugin and a theme from the folder beside the image’s own', function (): void {
    $this->folder->install('fixtures/hello-extension', '1.0.0');
    $id = $this->folder->install('fixtures/hello-theme', '1.0.0');

    $loaded = ($this->child)();

    expect($loaded['release'])->toBe($this->folder->root.'/releases/'.$id)
        ->and($loaded['held'])->toBeNull()
        ->and($loaded['composer'])->toContain('fixtures/hello-extension')
        ->and($loaded['plugins'])->toContain('fixtures/hello-extension')
        ->and($loaded['themes'])->toContain('hello-theme');
})->group('composer');

it('holds a release that wasn’t checked against this core', function (): void {
    $id = $this->folder->install('fixtures/hello-extension', '1.0.0');
    unlink($this->folder->store->path($id).'/compat/'.ExtensionsFolder::pins()['hash']);

    $loaded = ($this->child)();

    expect($loaded['release'])->toBeNull()
        ->and($loaded['held'])->toBe($this->folder->root.'/releases/'.$id)
        ->and($loaded['composer'])->not->toContain('fixtures/hello-extension')
        ->and($loaded['plugins'])->not->toContain('fixtures/hello-extension');
})->group('composer');

it('loads nothing in the installer, or with no folder, or with no pins', function (array $environment): void {
    $this->folder->install('fixtures/hello-extension', '1.0.0');

    $loaded = ($this->child)($environment);

    expect($loaded['release'])->toBeNull()
        ->and($loaded['held'])->toBeNull()
        ->and($loaded['composer'])->not->toContain('fixtures/hello-extension');
})->with([
    'the installer' => [['TEME_EXTENSIONS_ROLE' => 'installer']],
    'no folder' => [['TEME_EXTENSIONS_PATH' => '']],
    'no pins' => [['TEME_CORE_PINS' => '/nowhere/teme-core-pins.php']],
])->group('composer');

it('loads the new release in the next process once the link is switched', function (): void {
    $first = $this->folder->install('fixtures/hello-extension', '1.0.0');
    expect(($this->child)()['release'])->toEndWith($first);

    $second = $this->folder->install('fixtures/hello-theme', '1.0.0');

    expect(($this->child)()['release'])->toEndWith($second);
})->group('composer');

it('never lets a class in the folder replace core’s own', function (): void {
    $shadow = $this->folder->root.'/shadow';
    mkdir($shadow.'/src/Domain/Extensions', 0755, true);
    file_put_contents($shadow.'/composer.json', json_encode([
        'name' => 'fixtures/shadow', 'type' => 'library', 'version' => '1.0.0',
        'autoload' => ['psr-4' => ['App\\' => 'src/']],
    ]));
    file_put_contents($shadow.'/src/Domain/Extensions/ActiveRelease.php', "<?php\n\nnamespace App\\Domain\\Extensions;\n\nfinal class ActiveRelease {}\n");

    $this->folder->install('fixtures/shadow', '1.0.0', ['type' => 'path', 'url' => $shadow, 'options' => ['symlink' => false]]);

    $loaded = ($this->child)();

    expect($loaded['activeRelease'])->toBe(base_path('app/Domain/Extensions/ActiveRelease.php'))
        ->and($loaded['loaders'][0])->toBe(base_path('vendor'))
        ->and(end($loaded['loaders']))->toBe($loaded['release'].'/vendor');
})->group('composer');

it('reads the loaded release’s caches and installed packages, the image’s first', function (): void {
    $release = $this->folder->root.'/releases/'.strtolower((string) Str::ulid());
    mkdir($release.'/cache/abc123', 0755, true);
    mkdir($release.'/vendor/composer', 0755, true);
    file_put_contents($release.'/cache/abc123/teme-themes.php', '<?php return '.var_export(['from-release' => ['code' => 'from-release', 'path' => $release.'/vendor/x/from-release', 'manifest' => null, 'errors' => ['Only a test.']]], true).';');
    file_put_contents($release.'/vendor/composer/installed.json', json_encode(['packages' => [
        ['name' => 'fixtures/only-here', 'type' => 'teme-plugin', 'autoload' => ['psr-4' => ['Only\\' => 'src/']]],
        ['name' => 'laravel/framework', 'type' => 'library', 'autoload' => ['psr-4' => ['Shadow\\' => 'src/']]],
    ]]));

    app()->instance(ActiveRelease::class, new ActiveRelease($release, 'abc123'));
    app(ThemeRegistry::class)->flush();

    $installed = new InstalledPackages;

    expect(app(ThemeRegistry::class)->all())->toHaveKey('from-release')
        ->and($installed->entry('fixtures/only-here')['type'])->toBe('teme-plugin')
        ->and($installed->entry('laravel/framework')['autoload']['psr-4'])->not->toHaveKey('Shadow\\');

    app()->forgetInstance(ActiveRelease::class);
    app(ThemeRegistry::class)->flush();
});
