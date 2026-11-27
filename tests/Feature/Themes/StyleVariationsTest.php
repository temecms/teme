<?php

declare(strict_types=1);

use App\Domain\Design\Operations\CreateDesignPreview;
use App\Domain\Design\Operations\Input\CreateDesignPreviewInput;
use App\Domain\Design\Operations\Input\PublishDesignInput;
use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\PublishDesign;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->folder = sys_get_temp_dir().'/teme-styles-'.getmypid().'-'.uniqid().'/skinned';
    mkdir($this->folder.'/views/templates', 0777, true);
    mkdir($this->folder.'/public/fonts', 0777, true);
    file_put_contents($this->folder.'/views/templates/index.blade.php', '<x-teme::document><main id="main"></main></x-teme::document>');
    file_put_contents($this->folder.'/public/fonts/body.woff2', 'font');
    file_put_contents($this->folder.'/public/fonts/display.woff2', 'font');
    file_put_contents($this->folder.'/teme-theme.json', (string) json_encode(pageManifest([
        'code' => 'skinned',
        'name' => 'Skinned',
        'fonts' => [
            ['family' => 'Body', 'file' => 'fonts/body.woff2', 'weight' => '400', 'style' => 'normal', 'preload' => true],
            ['family' => 'Display', 'file' => 'fonts/display.woff2', 'weight' => '700', 'style' => 'normal', 'preload' => false],
        ],
        'tokens' => ['base' => '#ffffff', 'accent' => '#b3261e'],
        'custom_tokens' => ['surface' => '#f5f5f5'],
        'styles' => [
            'classic' => [
                'label' => 'Classic',
                'tokens' => ['base' => '#ffffff', 'accent' => '#b3261e'],
                'custom_tokens' => ['surface' => '#f5f5f5'],
                'fonts' => 'fonts/body.woff2',
            ],
            'bold' => [
                'label' => 'Bold',
                'tokens' => ['base' => '#111111', 'accent' => '#ffcc00'],
                'tokens_dark' => ['base' => '#000000'],
                'custom_tokens' => ['surface' => '#222222'],
                'fonts' => 'fonts/display.woff2',
            ],
        ],
    ])));
    config(['teme.theme_paths' => [$this->folder]]);
    themeActivate('skinned');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create()->assignRole('author');
});

afterEach(function (): void {
    File::deleteDirectory(dirname($this->folder));
});

it('switches written tokens and the preloaded font when the style setting changes', function (): void {
    pageArticle($this->author, 'Styled');

    $html = addrGet('/styled/')->assertOk()->getContent();

    expect($html)
        ->toContain('--teme-base:#ffffff;--teme-accent:#b3261e;--teme-surface:#f5f5f5')
        ->toContain('<link rel="preload" as="font" type="font/woff2" href="'.asset('themes/skinned/fonts/body.woff2').'" crossorigin>')
        ->not->toContain('<link rel="preload" as="font" type="font/woff2" href="'.asset('themes/skinned/fonts/display.woff2').'" crossorigin>');

    expect(app(SettingsReader::class)->string('theme.skinned', 'style'))->toBe('classic');

    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.skinned' => ['style' => 'bold']], (string) $this->admin->id));
    app(PublishDesign::class)->execute(new PublishDesignInput((string) $this->admin->id));

    $bold = addrGet('/styled/')->assertOk()->getContent();

    expect($bold)
        ->toContain('--teme-base:#111111;--teme-accent:#ffcc00;--teme-surface:#222222')
        ->toContain('<link rel="preload" as="font" type="font/woff2" href="'.asset('themes/skinned/fonts/display.woff2').'" crossorigin>')
        ->not->toContain('<link rel="preload" as="font" type="font/woff2" href="'.asset('themes/skinned/fonts/body.woff2').'" crossorigin>');
});

it('shows a draft style on its preview only', function (): void {
    pageArticle($this->author, 'Draft style');
    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput(['theme.skinned' => ['style' => 'bold']], (string) $this->admin->id));
    $url = app(CreateDesignPreview::class)->execute(new CreateDesignPreviewInput('/draft-style/', (string) $this->admin->id));

    addrGet($url)->assertOk()
        ->assertSee('--teme-base:#111111', false)
        ->assertSee('themes/skinned/fonts/display.woff2', false);

    addrGet('/draft-style/')->assertOk()
        ->assertSee('--teme-base:#ffffff', false)
        ->assertDontSee('--teme-base:#111111', false)
        ->assertSee('themes/skinned/fonts/body.woff2', false);
});

it('overlays a style\'s tokens on the base instead of replacing them', function (): void {
    file_put_contents($this->folder.'/teme-theme.json', (string) json_encode(pageManifest([
        'code' => 'skinned',
        'name' => 'Skinned',
        'fonts' => [
            ['family' => 'Body', 'file' => 'fonts/body.woff2', 'weight' => '400', 'style' => 'normal', 'preload' => true],
        ],
        'tokens' => ['base' => '#ffffff', 'accent' => '#b3261e', 'contrast' => '#111111'],
        'custom_tokens' => ['surface' => '#f5f5f5', 'radius' => '0.25rem'],
        'styles' => [
            'classic' => [
                'label' => 'Classic',
                'tokens' => ['accent' => '#0b5394'],
                'custom_tokens' => ['surface' => '#e8eef5'],
            ],
        ],
    ])));
    themeActivate('skinned');
    pageArticle($this->author, 'Overlay style');

    expect(addrGet('/overlay-style/')->assertOk()->getContent())
        ->toContain('--teme-base:#ffffff;--teme-accent:#0b5394;--teme-contrast:#111111;--teme-surface:#e8eef5;--teme-radius:0.25rem');
});

it('refuses a style whose fonts file is not declared, or a setting that shadows style', function (): void {
    $errors = app(ManifestValidator::class)->errors(pageManifest([
        'fonts' => [['family' => 'Body', 'file' => 'fonts/newsreader.woff2', 'weight' => '400', 'style' => 'normal', 'preload' => true]],
        'styles' => [
            'classic' => ['label' => 'Classic', 'fonts' => 'fonts/missing.woff2'],
        ],
        'settings' => [
            ['key' => 'style', 'type' => 'select', 'label' => 'Mine', 'fieldset' => 'site', 'options' => ['a' => 'A'], 'default' => 'a'],
        ],
    ]), base_path('tests/Fixtures/themes/good'));

    expect(implode("\n", $errors))
        ->toContain('styles.classic.fonts')
        ->toContain('"style"');
});
