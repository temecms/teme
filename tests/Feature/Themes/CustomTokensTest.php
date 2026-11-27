<?php

declare(strict_types=1);

use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->path = base_path('tests/Fixtures/themes/good');
    $this->validator = app(ManifestValidator::class);
});

it('writes a custom token as --teme-<name> after the core tokens', function (): void {
    $folder = sys_get_temp_dir().'/teme-custom-'.getmypid().'-'.uniqid().'/custom';
    mkdir($folder.'/views/templates', 0777, true);
    file_put_contents($folder.'/views/templates/index.blade.php', '<x-teme::document><main id="main"></main></x-teme::document>');
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest([
        'code' => 'custom',
        'name' => 'Custom',
        'tokens' => ['base' => '#ffffff', 'accent' => '#b3261e'],
        'custom_tokens' => ['surface' => '#f0eee8', 'radius' => '0.5rem'],
        'custom_tokens_dark' => ['surface' => '#1a1917'],
    ])));
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('custom');
    $author = User::factory()->create()->assignRole('author');
    pageArticle($author, 'Custom tokens');

    $html = addrGet('/custom-tokens/')->assertOk()->getContent();

    expect($html)
        ->toContain('--teme-base:#ffffff;--teme-accent:#b3261e;--teme-surface:#f0eee8;--teme-radius:0.5rem')
        ->toContain('--teme-surface:#1a1917');

    File::deleteDirectory(dirname($folder));
});

it('refuses a custom token whose name equals a core token', function (): void {
    $errors = $this->validator->errors(pageManifest([
        'custom_tokens' => ['accent' => '#00ff00', 'surface' => '#eee'],
    ]), $this->path);

    expect(implode("\n", $errors))
        ->toContain('custom token "accent"')
        ->toContain('core token');
});

it('refuses a custom token with a bad value', function (): void {
    $errors = $this->validator->errors(pageManifest([
        'custom_tokens' => ['surface' => 'red; color: blue'],
        'custom_tokens_dark' => ['radius' => '0.5rem}'],
    ]), $this->path);

    expect(implode("\n", $errors))
        ->toContain('The custom token "surface" must have a CSS value, with no ; { } < or >.')
        ->toContain('The custom token "radius" in custom_tokens_dark must have a CSS value, with no ; { } < or >.');
});
