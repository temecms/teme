<?php

declare(strict_types=1);

use App\Domain\Extensions\PackagePictures;
use App\Domain\Plugins\ManifestValidator as PluginManifestValidator;
use App\Domain\Themes\ManifestValidator as ThemeManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Plugins\PluginFixtures;

// A plugin's and a theme's icon and screenshots, as their manifests declare them.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.version' => PluginFixtures::TEME]);
    $this->plugin = fn (array $keys): array => app(PluginManifestValidator::class)->errors([...PluginFixtures::manifest('hello'), ...$keys], PluginFixtures::path('hello'), null);
    $this->theme = function (array $keys): array {
        $manifest = json_decode((string) file_get_contents(base_path('themes/default/teme-theme.json')), true, 512, JSON_THROW_ON_ERROR);

        return app(ThemeManifestValidator::class)->errors([...$manifest, ...$keys], base_path('themes/default'));
    };
    $this->schema = fn (string $kind): array => json_decode((string) file_get_contents(base_path("packages/teme-contracts/schema/teme-{$kind}.schema.json")), true, 512, JSON_THROW_ON_ERROR)['properties'];
});

afterEach(fn () => PluginFixtures::forget());

it('accepts an icon and up to eight screenshots with captions, in a plugin’s manifest and a theme’s', function (string $kind): void {
    $shots = array_map(fn (int $n): array => ['path' => "shots/{$n}.png", 'caption' => str_repeat('a', 140)], range(1, 8));
    $shots[0] = ['path' => 'shots/first.JPEG'];

    expect(($this->{$kind})(['icon' => 'art/icon.svg', 'screenshots' => $shots]))->toBe([]);
})->with(['plugin', 'theme']);

it('refuses an icon or a screenshot that isn’t a picture inside the package, with its sentence', function (string $kind, array $keys, string $sentence): void {
    expect(($this->{$kind})($keys))->toContain(str_replace(':kind', $kind, $sentence));
})->with(['plugin', 'theme'])->with([
    'icon outside' => [['icon' => '../icon.png'], 'icon must be a path inside the :kind, with no .., to a .png, .jpg, .jpeg, .webp or .svg picture.'],
    'icon of another type' => [['icon' => 'icon.gif'], 'icon must be a path inside the :kind, with no .., to a .png, .jpg, .jpeg, .webp or .svg picture.'],
    'icon not text' => [['icon' => ['icon.png']], 'icon must be a path inside the :kind, with no .., to a .png, .jpg, .jpeg, .webp or .svg picture.'],
    'screenshots not a list' => [['screenshots' => ['one' => ['path' => 'one.png']]], 'screenshots must be a list of at most 8 pictures, each with a path and, if it has one, a caption.'],
    'nine screenshots' => [['screenshots' => array_fill(0, 9, ['path' => 'one.png'])], 'screenshots must be a list of at most 8 pictures, each with a path and, if it has one, a caption.'],
    'an svg screenshot' => [['screenshots' => [['path' => 'one.svg']]], 'screenshots[0].path must be a path inside the :kind, with no .., to a .png, .jpg, .jpeg or .webp picture.'],
    'a screenshot outside' => [['screenshots' => [['path' => 'a/../../one.png']]], 'screenshots[0].path must be a path inside the :kind, with no .., to a .png, .jpg, .jpeg or .webp picture.'],
    'a screenshot with no path' => [['screenshots' => [['caption' => 'The screen']]], 'screenshots[0].path must be a path inside the :kind, with no .., to a .png, .jpg, .jpeg or .webp picture.'],
    'a long caption' => [['screenshots' => [['path' => 'one.png', 'caption' => str_repeat('a', 141)]]], 'screenshots[0].caption must be text of at most 140 characters.'],
    'another key' => [['screenshots' => [['path' => 'one.png', 'alt' => 'One']]], 'screenshots[0] may hold only path and caption.'],
]);

it('describes icon and screenshots in both schemas as the validator checks them', function (string $kind, string $path, bool $icon, bool $screenshot): void {
    $p = ($this->schema)($kind);

    expect(preg_match(PackagePictures::ICON, $path) === 1)->toBe($icon)
        ->and(preg_match('~'.$p['icon']['pattern'].'~u', $path) === 1)->toBe($icon)
        ->and(preg_match(PackagePictures::SCREENSHOT, $path) === 1)->toBe($screenshot)
        ->and(preg_match('~'.$p['screenshots']['items']['properties']['path']['pattern'].'~u', $path) === 1)->toBe($screenshot)
        ->and($p['screenshots']['maxItems'])->toBe(PackagePictures::MOST_SCREENSHOTS)
        ->and($p['screenshots']['items']['properties']['caption']['maxLength'])->toBe(PackagePictures::LONGEST_CAPTION)
        ->and($p['screenshots']['items']['required'])->toBe(['path'])
        ->and($p['screenshots']['items']['additionalProperties'])->toBeFalse();
})->with(['plugin', 'theme'])->with([
    ['icon.png', true, true], ['art/icon.SVG', true, false], ['a/b.jpeg', true, true], ['a/b.Jpg', true, true], ['b.webp', true, true],
    ['/icon.png', false, false], ['../icon.png', false, false], ['a/../icon.png', false, false], ['a\\icon.png', false, false],
    ['c:icon.png', false, false], ['icon.gif', false, false], ['my icon.png', false, false],
]);

describe('on the Plugins screen', function (): void {
    beforeEach(function (): void {
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create()->assignRole('administrator');
        $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $this->row = fn (): array => collect($this->actingAs($this->admin)->get('/admin/plugins')->assertOk()->viewData('page')['props']['plugins'])->keyBy('name')->all()['tests/hello'];
    });

    it('draws the plugin’s own icon, served from its folder', function (): void {
        PluginFixtures::use(PluginFixtures::copy('hello', ['icon' => 'art/icon.png'], files: ['art/icon.png' => $this->png]));

        $icon = ($this->row)()['icon'];

        expect($icon)->toStartWith(route('plugins.icon', 'hello'));
        $this->actingAs($this->admin)->get($icon)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    });

    it('serves an svg icon so that it can run nothing when opened on its own', function (): void {
        PluginFixtures::use(PluginFixtures::copy('hello', ['icon' => 'icon.svg'], files: ['icon.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>']));

        $answer = $this->actingAs($this->admin)->get(($this->row)()['icon'])->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

        // Kept beside the admin's own policy, which a browser enforces too.
        expect($answer->headers->all('content-security-policy'))->toHaveCount(2)
            ->and($answer->headers->all('content-security-policy')[1])->toContain("default-src 'none'")->toContain('sandbox');
    });

    it('draws initials when the icon is missing, isn’t the picture its name says, or there is none', function (array $manifest, array $files): void {
        PluginFixtures::use(PluginFixtures::copy('hello', $manifest, files: $files));

        expect(($this->row)()['icon'])->toBeNull();
        $this->actingAs($this->admin)->get(route('plugins.icon', 'hello'))->assertNotFound();
    })->with([
        'missing' => [['icon' => 'icon.png'], []],
        'not a png' => [['icon' => 'icon.png'], ['icon.png' => 'not a picture']],
        'none declared' => [[], []],
    ]);

    it('lets only a person who manages plugins fetch an icon', function (): void {
        PluginFixtures::use(PluginFixtures::copy('hello', ['icon' => 'icon.png'], files: ['icon.png' => $this->png]));
        $editor = User::factory()->create()->assignRole('editor');

        $this->actingAs($editor)->get(route('plugins.icon', 'hello'))->assertForbidden();
    });
});
