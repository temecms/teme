<?php

declare(strict_types=1);

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Seo\WholeSite;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\Setting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->item = cmItem(User::factory()->create(['name' => 'Jane Doe'])->assignRole('author'), 'Harbour news');
    $this->folder = null;

    // A section as another part registers one, under the group's tag.
    $this->section = function (string $key, int $order, string $setting): void {
        app()->instance("test.section.{$key}", new readonly class($key, $order, $setting) implements SearchAndSocialSection
        {
            public function __construct(private string $key, private int $order, private string $setting) {}

            public function order(): int
            {
                return $this->order;
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return ucfirst($this->key);
            }

            public function settings(): array
            {
                return [Setting::boolean($this->setting, 'A switch', 'A switch for this test.', '0.1.0')];
            }
        });
        app()->tag(["test.section.{$key}"], SearchAndSocial::SECTIONS);
    };

    // The group is built at boot; a section a test adds afterwards needs it built again.
    $this->rebuild = fn () => app(SettingsRegistry::class)->replace(SearchAndSocial::group(app()->tagged(SearchAndSocial::SECTIONS)));
});

afterEach(function (): void {
    if ($this->folder !== null) {
        cacheOff($this->folder);
    }
});

it('registers Search and social for administrators, with its first section, The whole site, and Discourage search engines off (I-850)', function (): void {
    $group = app(SettingsRegistry::class)->find('seo');

    expect($group?->label)->toBe('Search and social')
        ->and($group?->description)->toBe('Titles in search results, what search engines may list, social cards, feeds, robots.txt, the API and llms.txt, and the 404 log.')
        ->and($group?->ability)->toBe('settings.seo.manage')
        ->and($group?->cacheTags)->toBe(['seo'])
        ->and($group?->sensitive)->toBeFalse()
        ->and($group?->screen)->toBeNull()
        ->and(array_map(fn (Setting $setting): string => $setting->key, $group?->settings ?? []))->toContain('discourage_search_engines')
        ->and($group?->setting('discourage_search_engines')?->label)->toBe('Discourage search engines')
        ->and($group?->setting('discourage_search_engines')?->description)->toBe('Every page asks search engines not to list it and the sitemap is switched off, so search engines drop the site. For a site that isn’t ready to be found.')
        ->and($group?->setting('discourage_search_engines')?->default)->toBeFalse()
        ->and(array_map(fn (SearchAndSocialSection $section): array => [$section->order(), $section->key(), $section->label()], SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS))))
        ->toContain([200, 'whole-site', 'The whole site'])
        ->and(WholeSite::discouraged())->toBeFalse();
});

it('orders sections by their own order, wherever they were registered, and the group\'s settings with them', function (): void {
    ($this->section)('late', 900, 'late_switch');
    ($this->section)('early', 150, 'early_switch');
    ($this->rebuild)();

    $keys = array_map(fn (SearchAndSocialSection $section): string => $section->key(), SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS)));
    $settings = array_map(fn (Setting $setting): string => $setting->key, app(SettingsRegistry::class)->find('seo')?->settings ?? []);

    // Read only this test's sections and the whole site's: the other parts of
    // 1.9 register sections of their own beside them (I-1020).
    expect(array_values(array_intersect($keys, ['early', 'whole-site', 'late'])))->toBe(['early', 'whole-site', 'late'])
        ->and(array_values(array_intersect($settings, ['early_switch', 'discourage_search_engines', 'late_switch'])))->toBe(['early_switch', 'discourage_search_engines', 'late_switch']);
});

it('refuses two sections with one order or one key, and a setting two sections declare, naming them', function (string $case, string $message): void {
    match ($case) {
        'one order' => ($this->section)('titles', 200, 'title_switch'),
        // 301, beside no real section's order, so each case meets only its own clash.
        'one key' => ($this->section)('whole-site', 301, 'other_switch'),
        default => ($this->section)('another', 301, 'discourage_search_engines'),
    };

    expect($this->rebuild)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'one order' => ['one order', 'Search and social sections [whole-site] and [titles] share the order 200.'],
    'one key' => ['one key', 'Two Search and social sections have the key [whole-site].'],
    'a setting declared twice' => ['a setting declared twice', 'Setting [seo.discourage_search_engines] is declared twice.'],
]);

it('lets administrators change it on the settings page, and nobody else (I-850)', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($this->admin)->get('/admin/settings/seo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/search-and-social')
            ->where('sections', fn (Collection $sections): bool => $sections->contains(fn (array $section): bool => $section['key'] === 'whole-site'
                && collect($section['fields'])->contains(fn (array $field): bool => $field['key'] === 'discourage_search_engines' && $field['type'] === 'boolean' && $field['label'] === 'Discourage search engines')))
            ->where('values.discourage_search_engines', false));

    $this->actingAs($this->admin)
        ->put('/admin/settings/seo', ['values' => ['discourage_search_engines' => true], 'expected_version' => 0])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/seo');

    expect(WholeSite::discouraged())->toBeTrue();

    $this->actingAs($editor)->get('/admin/settings/seo')->assertForbidden();
    $this->actingAs($editor)->put('/admin/settings/seo', ['values' => ['discourage_search_engines' => false], 'expected_version' => 1])->assertForbidden();

    expect(WholeSite::discouraged())->toBeTrue();
});

it('tells search engines to keep away from every page while the switch is on, a search\'s and a comment page\'s too (I-291)', function (string $page): void {
    $comment = cmComment($this->item, attributes: ['author_name' => 'Bo Reader']);
    cmSettings([WholeSite::DISCOURAGE => true], SearchAndSocial::KEY);

    $html = (string) addrGet(match ($page) {
        'an article' => '/harbour-news/',
        'a search' => '/?s=harbour',
        default => "/_teme/comment/{$comment->id}/reply",
    })->assertOk()->getContent();

    expect(substr_count($html, '<meta name="robots"'))->toBe(1)
        ->and($html)->toContain('<meta name="robots" content="noindex, nofollow">');
})->with(['an article', 'a search', 'a comment\'s reply page']);

it('removes every stored page when the switch changes, once the change commits, and none when it rolls back (I-291)', function (): void {
    $this->folder = cacheOn();
    addrGet('/harbour-news/')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/?s=harbour')->assertHeader('X-Teme-Cache', 'MISS');
    $change = fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('seo', [WholeSite::DISCOURAGE => true], [], [], 0, (string) $this->admin->id));

    expect(cacheEntry('/harbour-news/')?->tags)->toContain('seo')
        ->and(cacheEntry('/?s=harbour')?->tags)->toContain('seo');

    cacheRolledBack($change);

    expect(cacheKept('/harbour-news/', '/?s=harbour'))->toBe(['/harbour-news/', '/?s=harbour']);

    $change();

    expect(cacheKept('/harbour-news/', '/?s=harbour'))->toBe([]);
});
