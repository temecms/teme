<?php

declare(strict_types=1);

use App\Domain\Design\Operations\ChangeBrandColour;
use App\Domain\Design\Operations\Input\ChangeBrandColourInput;
use App\Domain\Design\Operations\Input\PublishDesignInput;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\PublishDesign;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create()->assignRole('author');
});

it('carries a stored accent into brand so an existing site keeps its look', function (): void {
    config(['teme.theme_paths' => [base_path('themes/default')]]);
    themeActivate('default');
    DB::table('settings')->where('group_key', 'design')->update([
        'overrides' => json_encode(['theme' => 'default', 'accent' => '#0b5394']),
        'version' => 1,
    ]);

    $migration = require database_path('migrations/2027_11_27_100000_carry_design_accent_into_brand.php');
    $migration->up();
    Cache::memo()->forget(SettingsRepository::cacheKey('design'));

    expect(app(SettingsReader::class)->string('design', 'brand'))->toBe('#0b5394')
        ->and(json_decode((string) DB::table('settings')->where('group_key', 'design')->value('overrides'), true))->not->toHaveKey('accent');

    pageArticle($this->author, 'Carried brand');
    $html = addrGet('/carried-brand/')->assertOk()->getContent();

    expect($html)
        ->toContain('--teme-brand:#0b5394')
        ->toContain('--teme-accent:#0b5394');
});

it('restores accent on down and carries brand again on up, purging theme pages', function (): void {
    config(['teme.theme_paths' => [base_path('themes/default')]]);
    themeActivate('default');
    pageArticle($this->author, 'Migration purge');
    cacheShown(['/migration-purge/' => ['theme'], '/unrelated/' => ['content:other']]);

    DB::table('settings')->where('group_key', 'design')->update([
        'overrides' => json_encode(['theme' => 'default', 'accent' => '#0b5394']),
        'version' => 1,
    ]);

    $migration = require database_path('migrations/2027_11_27_100000_carry_design_accent_into_brand.php');
    $migration->up();
    Cache::memo()->forget(SettingsRepository::cacheKey('design'));

    expect(app(SettingsReader::class)->string('design', 'brand'))->toBe('#0b5394')
        ->and(cacheKept('/migration-purge/', '/unrelated/'))->toBe(['/unrelated/']);

    cacheShown(['/migration-purge/' => ['theme'], '/unrelated/' => ['content:other']]);
    $migration->down();
    Cache::memo()->forget(SettingsRepository::cacheKey('design'));

    expect(json_decode((string) DB::table('settings')->where('group_key', 'design')->value('overrides'), true))
        ->toHaveKey('accent', '#0b5394')
        ->not->toHaveKey('brand')
        ->and(cacheKept('/migration-purge/', '/unrelated/'))->toBe(['/unrelated/']);

    cacheShown(['/migration-purge/' => ['theme'], '/unrelated/' => ['content:other']]);
    $migration->up();
    Cache::memo()->forget(SettingsRepository::cacheKey('design'));

    expect(app(SettingsReader::class)->string('design', 'brand'))->toBe('#0b5394')
        ->and(cacheKept('/migration-purge/', '/unrelated/'))->toBe(['/unrelated/']);
});

it('keeps the theme\'s own accent when Use the site\'s brand colour is off', function (): void {
    config(['teme.theme_paths' => [base_path('themes/default')]]);
    themeActivate('default');
    app(ChangeBrandColour::class)->execute(new ChangeBrandColourInput('#0b5394', 0, (string) $this->admin->id));
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.default' => ['use_brand_colour' => false]], (string) $this->admin->id));
    app(PublishDesign::class)->execute(new PublishDesignInput((string) $this->admin->id));
    pageArticle($this->author, 'Own accent');

    $html = addrGet('/own-accent/')->assertOk()->getContent();

    expect($html)
        ->toContain('--teme-brand:#0b5394')
        ->toContain('--teme-accent:#b3261e')
        ->not->toContain('--teme-accent:#0b5394');
});

it('writes theme-color from the brand colour', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    app(ChangeBrandColour::class)->execute(new ChangeBrandColourInput('#0b5394', 0, (string) $this->admin->id));
    pageArticle($this->author, 'Theme color');

    expect(addrGet('/theme-color/')->assertOk()->getContent())
        ->toContain('<meta name="theme-color" content="#0b5394">');
});

it('refuses a theme that sets the read-only brand token', function (): void {
    $errors = app(ManifestValidator::class)->errors(pageManifest([
        'tokens' => ['brand' => '#0b5394'],
    ]), base_path('tests/Fixtures/themes/good'));

    expect(implode("\n", $errors))->toContain('read-only');
});
