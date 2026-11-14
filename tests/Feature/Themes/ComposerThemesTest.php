<?php

declare(strict_types=1);

use App\Domain\Themes\Theme;
use App\Domain\Themes\ThemeRegistry;
use Composer\InstalledVersions;

beforeEach(function (): void {
    config(['teme.theme_paths' => [], 'teme.theme_cache' => '']);

    $this->composerState = array_map(
        fn (string $name): mixed => (new ReflectionProperty(InstalledVersions::class, $name))->getValue(),
        ['installed', 'installedByVendor', 'installedIsLocalDir'],
    );
});

afterEach(function (): void {
    foreach (['installed', 'installedByVendor', 'installedIsLocalDir'] as $i => $name) {
        (new ReflectionProperty(InstalledVersions::class, $name))->setValue(null, $this->composerState[$i]);
    }
});

it('finds the default theme, a path-repository package, in Composer\'s runtime data', function (): void {
    expect(InstalledVersions::getInstalledPackagesByType('teme-theme'))->toContain('teme/theme-default')
        ->and(app(ThemeRegistry::class)->find('default')?->path)->toBe(realpath(base_path('themes/default')));
});

it('finds a theme package that only Composer\'s runtime data lists', function (): void {
    // installed.json has no such package, so only InstalledVersions can find it.
    InstalledVersions::reload([
        'root' => ['name' => 'fixture/root', 'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'project', 'install_path' => base_path(), 'aliases' => [], 'dev' => true],
        'versions' => [
            'fixture/theme-good' => ['pretty_version' => '0.1.0', 'version' => '0.1.0.0', 'reference' => null, 'type' => 'teme-theme', 'install_path' => base_path('tests/Fixtures/themes/good'), 'aliases' => [], 'dev_requirement' => false],
        ],
    ]);
    app(ThemeRegistry::class)->flush();

    $good = app(ThemeRegistry::class)->find('good');

    expect($good?->usable())->toBeTrue()
        ->and($good?->path)->toBe(realpath(base_path('tests/Fixtures/themes/good')));
});

it('lists a theme once when Composer reports its package twice', function (): void {
    // After reload() with its own data, Composer reports every package twice.
    InstalledVersions::reload(require base_path('vendor/composer/installed.php'));
    app(ThemeRegistry::class)->flush();

    $defaults = array_filter(app(ThemeRegistry::class)->all(), fn (Theme $theme): bool => $theme->code === 'default');

    expect($defaults)->toHaveCount(1);
});
