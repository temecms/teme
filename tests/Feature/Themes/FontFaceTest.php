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

    expect($html)->toContain('@font-face{font-family:"Body Face";font-weight:400 700;font-style:normal;src:url("'.$href.'") format("woff2");font-display:swap}');

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

it('writes no @font-face when face is false, and still preloads', function (): void {
    $folder = sys_get_temp_dir().'/teme-noface-'.getmypid().'-'.uniqid().'/noface';
    mkdir($folder.'/views/templates', 0777, true);
    mkdir($folder.'/public/fonts', 0777, true);
    file_put_contents($folder.'/views/templates/index.blade.php', '<x-teme::document><main id="main"></main></x-teme::document>');
    file_put_contents($folder.'/public/fonts/body.woff2', 'font');
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest([
        'code' => 'noface',
        'name' => 'No Face',
        'fonts' => [
            ['family' => 'Body Face', 'file' => 'fonts/body.woff2', 'weight' => '400', 'style' => 'normal', 'preload' => true, 'face' => false],
        ],
        'tokens' => ['base' => '#ffffff'],
    ])));
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('noface');
    pageArticle($this->author, 'No face rule');

    $html = addrGet('/no-face-rule/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('@font-face')
        ->toContain('rel="preload" as="font"')
        ->toContain('themes/noface/fonts/body.woff2');

    File::deleteDirectory(dirname($folder));
});

it('writes unicode-range when the font declares it', function (): void {
    $folder = sys_get_temp_dir().'/teme-urange-'.getmypid().'-'.uniqid().'/urange';
    mkdir($folder.'/views/templates', 0777, true);
    mkdir($folder.'/public/fonts', 0777, true);
    file_put_contents($folder.'/views/templates/index.blade.php', '<x-teme::document><main id="main"></main></x-teme::document>');
    file_put_contents($folder.'/public/fonts/body.woff2', 'font');
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest([
        'code' => 'urange',
        'name' => 'Urange',
        'fonts' => [
            ['family' => 'Body Face', 'file' => 'fonts/body.woff2', 'weight' => '400', 'style' => 'normal', 'preload' => true, 'unicode_range' => 'U+0000-00FF'],
        ],
        'tokens' => ['base' => '#ffffff'],
    ])));
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('urange');
    pageArticle($this->author, 'Unicode range');

    expect(addrGet('/unicode-range/')->assertOk()->getContent())
        ->toContain('unicode-range:U+0000-00FF');

    File::deleteDirectory(dirname($folder));
});

it('refuses a bad face or unicode_range on a font', function (): void {
    $errors = app(\App\Domain\Themes\ManifestValidator::class)->errors(pageManifest([
        'fonts' => [
            ['family' => 'Body', 'file' => 'fonts/newsreader.woff2', 'weight' => '400', 'style' => 'normal', 'preload' => true, 'face' => 'no', 'unicode_range' => 'U+0000;'],
        ],
    ]), base_path('tests/Fixtures/themes/good'));

    expect(implode("\n", $errors))
        ->toContain('face that isn\'t true or false')
        ->toContain('unicode_range');
});

it('refuses a family or weight with CSS-breaking characters', function (): void {
    $errors = app(\App\Domain\Themes\ManifestValidator::class)->errors(pageManifest([
        'fonts' => [
            ['family' => 'Bad";}', 'file' => 'fonts/newsreader.woff2', 'weight' => '400', 'style' => 'normal', 'preload' => true],
            ['family' => 'Ok', 'file' => 'fonts/newsreader.woff2', 'weight' => '400;x', 'style' => 'normal', 'preload' => false],
        ],
    ]), base_path('tests/Fixtures/themes/good'));

    expect(implode("\n", $errors))->toContain('family and weight with no');
});

it('quotes the src url and picks format from the file extension', function (): void {
    $folder = sys_get_temp_dir().'/teme-fmt-'.getmypid().'-'.uniqid().'/fmt';
    mkdir($folder.'/views/templates', 0777, true);
    mkdir($folder.'/public/fonts', 0777, true);
    file_put_contents($folder.'/views/templates/index.blade.php', '<x-teme::document><main id="main"></main></x-teme::document>');
    file_put_contents($folder.'/public/fonts/body.woff', 'font');
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest([
        'code' => 'fmt',
        'name' => 'Fmt',
        'fonts' => [
            ['family' => 'Body Woff', 'file' => 'fonts/body.woff', 'weight' => '400', 'style' => 'normal', 'preload' => true],
        ],
        'tokens' => ['base' => '#ffffff'],
    ])));
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('fmt');
    pageArticle($this->author, 'Font format');

    $href = asset('themes/fmt/fonts/body.woff');

    expect(addrGet('/font-format/')->assertOk()->getContent())
        ->toContain('src:url("'.$href.'") format("woff")');

    File::deleteDirectory(dirname($folder));
});
