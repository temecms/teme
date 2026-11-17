<?php

declare(strict_types=1);

use App\Domain\Themes\ManifestValidator;
use App\Domain\Themes\ThemeRegistry;
use Illuminate\Support\ServiceProvider;

beforeEach(fn () => config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good'), base_path('tests/Fixtures/themes/broken')]]));

it('finds themes in the configured folders and reads their manifests', function (): void {
    $good = app(ThemeRegistry::class)->find('good');

    expect($good)->not->toBeNull()
        ->and($good->usable())->toBeTrue()
        ->and($good->manifest->name())->toBe('Fixture Good')
        ->and($good->manifest->perPage())->toBe(10);
});

it('marks a theme with manifest errors as broken, and says what is wrong', function (): void {
    $broken = app(ThemeRegistry::class)->find('broken');

    expect($broken->usable())->toBeFalse()
        ->and($broken->errors)->toContain('The template "single-page-landing" is declared but views/templates/single-page-landing.blade.php does not exist.')
        ->and(implode("\n", $broken->errors))->toContain('requires Teme');
});

it('finds the default theme through Composer', function (): void {
    config(['teme.theme_paths' => []]);

    expect(app(ThemeRegistry::class)->find('default'))->not->toBeNull();
});

it('keeps the default theme usable on every 0.x and 1.x release, so an update never loses a site its theme', function (string $version): void {
    // No cached verdict: the manifest is read and checked against this version.
    config(['teme.theme_paths' => [], 'teme.version' => $version, 'teme.theme_cache' => sys_get_temp_dir().'/teme-themes-none-'.getmypid().'.php']);
    app()->forgetInstance(ThemeRegistry::class);

    $default = app(ThemeRegistry::class)->find('default');

    expect($default?->errors)->toBe([])
        ->and($default?->usable())->toBeTrue();
})->with(['0.1.0-rc.18', '0.1.0', '0.2.0-alpha.1', '0.2.0', '0.9.3', '1.0.0', '1.4.2']);

it('caches only Composer\'s themes, reads theme_paths live, and clears the cache', function (): void {
    // A per-process file: bootstrap/cache is shared by the parallel gate (I-441).
    $cache = sys_get_temp_dir().'/teme-themes-'.getmypid().'-'.uniqid().'.php';
    config(['teme.theme_cache' => $cache]);

    $this->artisan('teme:themes:cache')->assertSuccessful();

    expect(array_keys(require $cache))->toContain('default')->not->toContain('good')
        ->and(app(ThemeRegistry::class)->find('good'))->not->toBeNull();

    $this->artisan('teme:themes:clear')->assertSuccessful();

    expect(is_file($cache))->toBeFalse();
});

it('builds and clears the theme cache with optimize and optimize:clear', function (): void {
    expect(ServiceProvider::$optimizeCommands['teme-themes'] ?? null)->toBe('teme:themes:cache')
        ->and(ServiceProvider::$optimizeClearCommands['teme-themes'] ?? null)->toBe('teme:themes:clear');
});

it('refuses a theme that names a parent, saying Teme has none', function (string $key): void {
    $good = json_decode((string) file_get_contents(base_path('tests/Fixtures/themes/good/teme-theme.json')), true, 512, JSON_THROW_ON_ERROR);

    expect(app(ManifestValidator::class)->errors([...$good, $key => 'default'], base_path('tests/Fixtures/themes/good')))
        ->toContain('Teme has no parent or child themes: copy the theme you would build on, and change the copy.');
})->with(['parent', 'extends']);

it('refuses the default theme on Teme 2.0', function (): void {
    // No cached verdict, so the manifest is read and checked against this version.
    config(['teme.theme_paths' => [], 'teme.version' => '2.0.0', 'teme.theme_cache' => sys_get_temp_dir().'/teme-themes-none-'.getmypid().'.php']);
    app()->forgetInstance(ThemeRegistry::class);
    $constraint = json_decode((string) file_get_contents(base_path('themes/default/teme-theme.json')), true, 512, JSON_THROW_ON_ERROR)['requires']['teme'];

    expect(app(ThemeRegistry::class)->find('default')?->errors)->toContain("The theme requires Teme {$constraint}, but this is Teme 2.0.0.");
});

it('finds a relative theme_paths entry when the working directory is public/', function (): void {
    $cwd = getcwd();
    config(['teme.theme_paths' => ['tests/Fixtures/themes/good'], 'teme.theme_cache' => '']);
    app()->forgetInstance(ThemeRegistry::class);
    chdir(base_path('public'));

    try {
        $good = app(ThemeRegistry::class)->find('good');

        expect($good)->not->toBeNull()
            ->and($good->usable())->toBeTrue()
            ->and($good->path)->toBe(realpath(base_path('tests/Fixtures/themes/good')));
    } finally {
        chdir($cwd ?: base_path());
    }
});
