<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('writes an @font-face rule with the same-origin address for each fonts entry', function (): void {
    $folder = sys_get_temp_dir().'/teme-faces-'.getmypid().'-'.uniqid().'/faced';
    mkdir($folder.'/views/templates', 0777, true);
    mkdir($folder.'/public/fonts', 0777, true);
    file_put_contents($folder.'/views/templates/index.blade.php', '<x-teme::document><main id="main"></main></x-teme::document>');
    file_put_contents($folder.'/public/fonts/body.woff2', 'font');
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest([
        'code' => 'faced',
        'name' => 'Faced',
        'fonts' => [
            ['family' => 'Body Face', 'file' => 'fonts/body.woff2', 'weight' => '400 700', 'style' => 'normal', 'preload' => true],
        ],
        'tokens' => ['base' => '#ffffff'],
    ])));
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('faced');
    pageArticle($this->author, 'Font face');

    $href = asset('themes/faced/fonts/body.woff2');
    $html = addrGet('/font-face/')->assertOk()->getContent();

    expect($html)->toContain('@font-face{font-family:"Body Face";font-weight:400 700;font-style:normal;src:url('.$href.') format("woff2");font-display:swap}');

    File::deleteDirectory(dirname($folder));
});

it('writes no @font-face when the theme leaves fonts out', function (): void {
    $folder = sys_get_temp_dir().'/teme-nofont-'.getmypid().'-'.uniqid().'/nofont';
    mkdir($folder.'/views/templates', 0777, true);
    file_put_contents($folder.'/views/templates/index.blade.php', '<x-teme::document><main id="main"></main></x-teme::document>');
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest([
        'code' => 'nofont',
        'name' => 'No Font',
        'tokens' => ['base' => '#ffffff'],
    ])));
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('nofont');
    pageArticle($this->author, 'No faces');

    expect(addrGet('/no-faces/')->assertOk()->getContent())->not->toContain('@font-face');

    File::deleteDirectory(dirname($folder));
});
