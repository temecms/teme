<?php

/**
 * Theme setting types beyond select, boolean, text and colour (issue 373):
 * number, image, menu, links, url and textarea — each saves, refuses a bad
 * value, and reaches the template in a readable form.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Design\Operations\Input\SaveDesignDraftInput;
use App\Domain\Design\Operations\SaveDesignDraft;
use App\Domain\Design\SiteDesign;
use App\Domain\Menus\Operations\CreateNavigation;
use App\Domain\Menus\Operations\Input\CreateNavigationInput;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Themes\ManifestValidator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
});

afterEach(function (): void {
    if (isset($this->typedThemeParent) && is_string($this->typedThemeParent)) {
        File::deleteDirectory($this->typedThemeParent);
    }
});

/**
 * Registers a temporary theme that declares these settings, activates it,
 * and returns its settings group key. Copies the good fixture so its
 * templates and assets stay on disk.
 *
 * @param  list<array<string, mixed>>  $settings
 */
function typedTheme(array $settings): string
{
    $source = base_path('tests/Fixtures/themes/good');
    $parent = sys_get_temp_dir().'/teme-themes-'.bin2hex(random_bytes(4));
    test()->typedThemeParent = $parent;
    $folder = $parent.'/typed';
    recurseCopy($source, $folder);
    $manifest = designManifest($settings);
    $manifest['code'] = 'typed';
    $manifest['name'] = 'Typed';
    file_put_contents($folder.'/teme-theme.json', (string) json_encode($manifest));
    config(['teme.theme_paths' => [$folder]]);
    themeActivate('typed');

    return 'theme.typed';
}

/** Copies a theme fixture folder into a fresh temporary directory. */
function recurseCopy(string $from, string $to): void
{
    mkdir($to, 0777, true);

    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $source = $from.'/'.$name;
        $target = $to.'/'.$name;
        is_dir($source) ? recurseCopy($source, $target) : copy($source, $target);
    }
}

it('accepts number, image, menu, links, url and textarea defaults, and refuses a bad one', function (): void {
    $validator = app(ManifestValidator::class);
    $path = base_path('tests/Fixtures/themes/good');

    expect($validator->errors(designManifest([
        ['key' => 'per_row', 'type' => 'number', 'label' => 'Per row', 'fieldset' => 'latest', 'default' => 3, 'min' => 1, 'max' => 6, 'step' => 1, 'unit' => 'columns'],
        ['key' => 'logo', 'type' => 'image', 'label' => 'Logo', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'footer_menu', 'type' => 'menu', 'label' => 'Footer menu', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'social', 'type' => 'links', 'label' => 'Social', 'fieldset' => 'site', 'default' => []],
        ['key' => 'policy', 'type' => 'url', 'label' => 'Policy', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'blurb', 'type' => 'textarea', 'label' => 'Blurb', 'fieldset' => 'site', 'default' => ''],
    ]), $path))->toBe([]);

    expect(implode("\n", $validator->errors(designManifest([
        ['key' => 'per_row', 'type' => 'number', 'label' => 'Per row', 'fieldset' => 'latest', 'default' => 99, 'min' => 1, 'max' => 6],
        ['key' => 'logo', 'type' => 'image', 'label' => 'Logo', 'fieldset' => 'site', 'default' => 12],
        ['key' => 'footer_menu', 'type' => 'menu', 'label' => 'Footer menu', 'fieldset' => 'site', 'default' => false],
        ['key' => 'social', 'type' => 'links', 'label' => 'Social', 'fieldset' => 'site', 'default' => 'no'],
        ['key' => 'policy', 'type' => 'url', 'label' => 'Policy', 'fieldset' => 'site', 'default' => 'ftp://x'],
        ['key' => 'blurb', 'type' => 'textarea', 'label' => 'Blurb', 'fieldset' => 'site', 'default' => 1],
    ]), $path)))
        ->toContain('"per_row"')
        ->toContain('"logo"')
        ->toContain('"footer_menu"')
        ->toContain('"social"')
        ->toContain('"policy"')
        ->toContain('"blurb"');
});

it('saves each new type and refuses a bad value, writing nothing', function (): void {
    $group = typedTheme([
        ['key' => 'per_row', 'type' => 'number', 'label' => 'Per row', 'fieldset' => 'latest', 'default' => 3, 'min' => 1, 'max' => 6, 'unit' => 'columns'],
        ['key' => 'logo', 'type' => 'image', 'label' => 'Logo', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'footer_menu', 'type' => 'menu', 'label' => 'Footer menu', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'social', 'type' => 'links', 'label' => 'Social', 'fieldset' => 'site', 'default' => []],
        ['key' => 'policy', 'type' => 'url', 'label' => 'Policy', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'blurb', 'type' => 'textarea', 'label' => 'Blurb', 'fieldset' => 'site', 'default' => ''],
    ]);

    $picture = pageImage($this->admin, 200, 200);
    $menu = app(CreateNavigation::class)->execute(new CreateNavigationInput('Footer', (string) $this->admin->id));

    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([
        $group => [
            'per_row' => 4,
            'logo' => $picture->id,
            'footer_menu' => $menu->id,
            'social' => [
                ['label' => 'Mastodon', 'address' => 'https://example.test/@harbour'],
            ],
            'policy' => 'https://example.test/policy/',
            'blurb' => "A short note\nabout the site.",
        ],
    ], (string) $this->admin->id));

    // Publish the draft so SiteDesign reads the live values.
    $overrides = (array) json_decode((string) DB::table('settings')->where('group_key', $group)->value('draft_values'), true);
    DB::table('settings')->where('group_key', $group)->update([
        'overrides' => json_encode($overrides),
        'draft_values' => null,
        'version' => 1,
    ]);
    Cache::memo()->forget(SettingsRepository::cacheKey($group));

    $reader = app(SettingsReader::class);

    expect($reader->get($group, 'per_row'))->toBe(4)
        ->and($reader->get($group, 'logo'))->toBe($picture->id)
        ->and($reader->get($group, 'footer_menu'))->toBe($menu->id)
        ->and($reader->get($group, 'social'))->toBe([
            ['label' => 'Mastodon', 'address' => 'https://example.test/@harbour'],
        ])
        ->and($reader->get($group, 'policy'))->toBe('https://example.test/policy/')
        ->and($reader->get($group, 'blurb'))->toBe("A short note\nabout the site.");

    expect(fn () => app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([
        $group => ['per_row' => 99],
    ], (string) $this->admin->id)))->toThrow(ValidationException::class);

    expect($reader->get($group, 'per_row'))->toBe(4);
});

it('gives the template an image\'s address and alt, and a menu\'s items as navigation slots do', function (): void {
    $group = typedTheme([
        ['key' => 'logo', 'type' => 'image', 'label' => 'Logo', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'footer_menu', 'type' => 'menu', 'label' => 'Footer menu', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'per_row', 'type' => 'number', 'label' => 'Per row', 'fieldset' => 'latest', 'default' => 3],
    ]);

    $picture = pageImage($this->admin, 200, 200);
    $picture->forceFill(['alt' => 'Harbour mark'])->save();
    $menu = navCreate($this->admin, 'Footer');
    navSave($this->admin, $menu, [
        ['kind' => 'url', 'url' => 'https://example.test/about/', 'label' => 'About'],
    ]);

    DB::table('settings')->updateOrInsert(['group_key' => $group], [
        'overrides' => json_encode([
            'logo' => $picture->id,
            'footer_menu' => $menu->id,
            'per_row' => 4,
        ]),
        'version' => 1,
    ]);
    Cache::memo()->forget(SettingsRepository::cacheKey($group));

    $settings = app(SiteDesign::class)->themeSettings();

    expect($settings['per_row'])->toBe(4)
        ->and($settings['logo'])->toMatchArray(['alt' => 'Harbour mark'])
        ->and($settings['logo']['url'])->toBeString()->not->toBe('')
        ->and($settings['footer_menu'])->toBeArray()
        ->and($settings['footer_menu'][0]['label'] ?? null)->toBe('About');
});

it('refuses too many links, a javascript address, and a link with no label', function (): void {
    $group = typedTheme([
        ['key' => 'social', 'type' => 'links', 'label' => 'Social', 'fieldset' => 'site', 'default' => []],
    ]);

    $tooMany = array_map(
        fn (int $n): array => ['label' => "Link {$n}", 'address' => "https://example.test/{$n}"],
        range(1, 13),
    );

    expect(fn () => app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([
        $group => ['social' => $tooMany],
    ], (string) $this->admin->id)))->toThrow(ValidationException::class);

    expect(fn () => app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([
        $group => ['social' => [['label' => 'X', 'address' => 'javascript:alert(1)']]],
    ], (string) $this->admin->id)))->toThrow(ValidationException::class);

    expect(fn () => app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([
        $group => ['social' => [['label' => '', 'address' => 'https://example.test/']]],
    ], (string) $this->admin->id)))->toThrow(ValidationException::class);

    expect(DB::table('settings')->where('group_key', $group)->value('draft_values'))->toBeNull();
});

it('builds theme settings once per request, and marks the current menu item', function (): void {
    $group = typedTheme([
        ['key' => 'footer_menu', 'type' => 'menu', 'label' => 'Footer menu', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'logo', 'type' => 'image', 'label' => 'Logo', 'fieldset' => 'site', 'default' => ''],
    ]);

    $picture = pageImage($this->admin, 200, 200);
    $page = addrPublished($this->admin, 'About');
    $menu = navCreate($this->admin, 'Footer');
    navSave($this->admin, $menu, [
        ['kind' => 'content', 'target_id' => $page->id, 'label' => 'About'],
    ]);

    DB::table('settings')->updateOrInsert(['group_key' => $group], [
        'overrides' => json_encode([
            'footer_menu' => $menu->id,
            'logo' => $picture->id,
        ]),
        'version' => 1,
    ]);
    Cache::memo()->forget(SettingsRepository::cacheKey($group));

    $design = app(SiteDesign::class);
    $design->start('/about/');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $first = $design->themeSettings();
    $afterOne = count(DB::getQueryLog());
    $second = $design->themeSettings();
    $afterTen = count(DB::getQueryLog());

    for ($i = 0; $i < 8; $i++) {
        $design->themeSettings();
    }

    $afterTen = count(DB::getQueryLog());

    expect($afterTen)->toBe($afterOne)
        ->and($first)->toBe($second)
        ->and($first['footer_menu'][0]['current'] ?? false)->toBeTrue();
});

it('refuses a cleared number, a number below its min, and a non-empty menu default', function (): void {
    $group = typedTheme([
        ['key' => 'per_row', 'type' => 'number', 'label' => 'Per row', 'fieldset' => 'latest', 'default' => 3, 'min' => 1, 'max' => 6, 'step' => 1],
    ]);

    expect(fn () => app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([
        $group => ['per_row' => ''],
    ], (string) $this->admin->id)))->toThrow(ValidationException::class);

    expect(fn () => app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([
        $group => ['per_row' => 0],
    ], (string) $this->admin->id)))->toThrow(ValidationException::class);

    $path = base_path('tests/Fixtures/themes/good');

    expect(implode("\n", app(ManifestValidator::class)->errors(designManifest([
        ['key' => 'footer_menu', 'type' => 'menu', 'label' => 'Footer', 'fieldset' => 'site', 'default' => 'abc'],
    ]), $path)))->toContain('"footer_menu"');
});

it('refuses a number that is not a multiple of its step, and keeps one that is', function (): void {
    $group = typedTheme([
        ['key' => 'per_row', 'type' => 'number', 'label' => 'Per row', 'fieldset' => 'latest', 'default' => 2, 'min' => 2, 'max' => 8, 'step' => 2],
    ]);

    expect(fn () => app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([
        $group => ['per_row' => 3],
    ], (string) $this->admin->id)))->toThrow(ValidationException::class)
        ->and(DB::table('settings')->where('group_key', $group)->value('draft_values'))->toBeNull();

    app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([
        $group => ['per_row' => 4],
    ], (string) $this->admin->id));

    expect((array) json_decode((string) DB::table('settings')->where('group_key', $group)->value('draft_values'), true))->toMatchArray(['per_row' => 4]);
});

it('refuses an image that is not a picture, a menu that does not exist, and an address that is not an address', function (): void {
    $group = typedTheme([
        ['key' => 'logo', 'type' => 'image', 'label' => 'Logo', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'footer_menu', 'type' => 'menu', 'label' => 'Footer menu', 'fieldset' => 'site', 'default' => ''],
        ['key' => 'policy', 'type' => 'url', 'label' => 'Policy', 'fieldset' => 'site', 'default' => ''],
    ]);
    $page = addrPublished($this->admin, 'About');
    $save = fn (array $values) => fn () => app(SaveDesignDraft::class)->execute(new SaveDesignDraftInput([$group => $values], (string) $this->admin->id));

    expect($save(['logo' => $page->id]))->toThrow(ValidationException::class)
        ->and($save(['logo' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']))->toThrow(ValidationException::class)
        ->and($save(['footer_menu' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']))->toThrow(ValidationException::class)
        ->and($save(['policy' => 'javascript:alert(1)']))->toThrow(ValidationException::class)
        ->and($save(['policy' => 'not an address']))->toThrow(ValidationException::class)
        ->and(DB::table('settings')->where('group_key', $group)->value('draft_values'))->toBeNull();
});
