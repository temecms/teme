<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Themes\ThemeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('shows themes, the accent, the theme\'s settings by fieldset, and the slots', function (): void {
    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page
        ->component('themes/index')
        ->where('themes', fn ($themes): bool => collect($themes)->pluck('code')->contains('good'))
        ->has('fieldsets.latest')
        ->where('fieldsets.latest.2.key', 'feed_images')
        ->where('fieldsets.latest.2.visibleWhen', ['feed_layout' => 'list'])
        ->has('slots', 2));
});

it('returns a preview link for the page type a section is about', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/admin/themes/preview-link', ['section' => 'latest'])
        ->assertOk()
        ->assertJsonPath('url', fn (string $url): bool => str_contains($url, 'teme_design_preview=1'));
});

it('previews the latest posts at the posts page when the home page is a page, and the link opens there', function (): void {
    $welcome = addrPublished($this->admin, 'Welcome', 'page');
    $news = addrPublished($this->admin, 'News', 'page');
    addrChange($this->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $welcome->id, 'postsPageId' => $news->id]);

    $url = $this->actingAs($this->admin)
        ->postJson('/admin/themes/preview-link', ['section' => 'latest'])
        ->assertOk()
        ->json('url');

    expect(parse_url($url, PHP_URL_PATH))->toBe('/news/');
    addrGet($url)->assertOk()->assertSee('Previewing unpublished design changes');
});

it('previews an article at its own address, and the link opens there', function (): void {
    addrPublished($this->admin, 'A story');

    $url = $this->actingAs($this->admin)
        ->postJson('/admin/themes/preview-link', ['section' => 'content'])
        ->assertOk()
        ->json('url');

    expect(parse_url($url, PHP_URL_PATH))->toBe('/a-story/');
    addrGet($url)->assertOk()->assertSee('Previewing unpublished design changes');
});

it('refuses the screen to editors', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->get('/admin/themes')->assertForbidden();
});

it('serves only the screenshot a theme declares, only from inside the theme, and only when it is an image', function (): void {
    $folder = sys_get_temp_dir().'/teme-design-'.getmypid().'-'.uniqid().'/good';
    mkdir($folder.'/views/templates', 0777, true);
    file_put_contents($folder.'/views/templates/index.blade.php', '<x-teme::document><main id="main"></main></x-teme::document>');
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest(['screenshot' => 'screenshot.png'])));
    imagepng(imagecreatetruecolor(12, 9), $folder.'/screenshot.png');
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('good');

    $this->actingAs($this->admin)->get('/admin/themes/good/screenshot')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
    $this->actingAs($this->admin)->get('/admin/themes/good/screenshot?path=teme-theme.json')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
    $this->actingAs($this->admin)->get('/admin/themes/nowhere/screenshot')->assertNotFound();

    // Named like an image, but not one.
    file_put_contents($folder.'/screenshot.png', 'Not an image, whatever its name says.');
    $this->actingAs($this->admin)->get('/admin/themes/good/screenshot')->assertNotFound();

    // A real image, but outside the theme's folder.
    imagepng(imagecreatetruecolor(12, 9), dirname($folder).'/escape.png');
    file_put_contents($folder.'/teme-theme.json', (string) json_encode(pageManifest(['screenshot' => '../escape.png'])));
    app(ThemeRegistry::class)->flush();
    $this->actingAs($this->admin)->get('/admin/themes/good/screenshot')->assertNotFound();

    File::deleteDirectory(dirname($folder));
});

it('leaves the brand colour to General', function (): void {
    $this->actingAs($this->admin)->get('/admin/themes')->assertInertia(fn (Assert $page) => $page
        ->component('themes/index')
        ->missing('accent')
        ->missing('accentSchemes'));

    expect(uiComponent('pages/themes/index.tsx'))->not->toContain('darkVariant(')->toContain('Settings › General');
});
