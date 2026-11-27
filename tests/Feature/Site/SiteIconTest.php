<?php

/**
 * The site icon in the public head (issue 388): the design setting wins over
 * the theme's default; PNG sizes and SVG; plugins may not add icon links.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Settings\SettingsRepository;
use App\Domain\Site\Document\LinkRelation;
use App\Domain\Site\Document\LinkTag;
use App\Domain\Site\Insertions\HeadCleaner;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
});

/**
 * A theme with a site_icon file in its public folder.
 */
function iconTheme(string $file = 'icon.svg'): string
{
    $source = base_path('tests/Fixtures/themes/good');
    $parent = sys_get_temp_dir().'/teme-icon-'.bin2hex(random_bytes(4));
    $folder = $parent.'/iconic';
    File::copyDirectory($source, $folder);
    File::ensureDirectoryExists($folder.'/public');

    if (str_ends_with($file, '.svg')) {
        file_put_contents($folder.'/public/'.$file, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" fill="#b3261e"/></svg>');
    } else {
        imagepng(imagecreatetruecolor(64, 64), $folder.'/public/'.$file);
    }

    $manifest = json_decode((string) file_get_contents($folder.'/teme-theme.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['code'] = 'iconic';
    $manifest['name'] = 'Iconic';
    $manifest['site_icon'] = $file;
    file_put_contents($folder.'/teme-theme.json', (string) json_encode($manifest));
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('iconic');

    return $folder;
}

it('accepts a site_icon path inside the theme\'s public folder', function (): void {
    $path = base_path('tests/Fixtures/themes/good');

    expect(app(ManifestValidator::class)->errors(array_replace(designManifest([]), [
        'site_icon' => 'icon.svg',
    ]), $path))->toBe([])
        ->and(implode("\n", app(ManifestValidator::class)->errors(array_replace(designManifest([]), [
            'site_icon' => '../escape.svg',
        ]), $path)))->toContain('site_icon');
});

it('links the setting\'s icon, the theme default when none, and the setting over the theme', function (): void {
    iconTheme('icon.svg');
    $home = addrPublished($this->admin, 'Harbour');

    $html = addrGet('/harbour/')->assertOk()->getContent();

    expect($html)->toContain('rel="icon"')
        ->and($html)->toContain('themes/iconic/icon.svg');

    $picture = pageImage($this->admin, 512, 512);
    DB::table('settings')->updateOrInsert(['group_key' => 'design'], [
        'overrides' => json_encode(['theme' => 'iconic', 'site_icon' => $picture->id]),
        'version' => 1,
    ]);
    Cache::memo()->forget(SettingsRepository::cacheKey('design'));

    $html = addrGet('/harbour/')->assertOk()->getContent();

    expect($html)->toContain('sizes="32x32"')
        ->and($html)->toContain('apple-touch-icon')
        ->and($html)->not->toContain('themes/iconic/icon.svg')
        ->and($html)->toContain('rel="manifest"');
});

it('lets core write icon and apple-touch-icon, and HeadCleaner drops a plugin\'s', function (): void {
    expect((new LinkTag(LinkRelation::Icon, 'https://example.test/icon.png', sizes: '32x32'))->html())
        ->toContain('rel="icon"')
        ->and((new LinkTag(LinkRelation::AppleTouchIcon, 'https://example.test/apple.png'))->html())
        ->toContain('rel="apple-touch-icon"');

    $cleaned = HeadCleaner::clean(
        '<link rel="icon" href="https://evil.example/x.ico"><link rel="apple-touch-icon" href="https://evil.example/a.png"><link rel="preconnect" href="https://fonts.example">'
    );

    expect($cleaned['html'])->not->toContain('rel="icon"')
        ->and($cleaned['html'])->not->toContain('apple-touch-icon')
        ->and($cleaned['html'])->toContain('preconnect');
});
