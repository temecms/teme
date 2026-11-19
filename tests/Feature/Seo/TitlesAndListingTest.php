<?php

declare(strict_types=1);

use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\Setting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
});

it('registers its section first in Search and social, with the separator and a row for each public type, the home page, each public taxonomy, authors, dates, search and not found (I-853, I-854)', function (): void {
    $section = new TitlesAndListing;
    $settings = [];

    foreach ($section->settings() as $setting) {
        $settings[$setting->key] = $setting;
    }

    $group = array_map(fn (Setting $setting): string => $setting->key, app(SettingsRegistry::class)->find('seo')?->settings ?? []);
    $first = SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS))[0];

    expect([$first->order(), $first->key(), $first->label()])->toBe([100, 'titles-and-listing', 'Titles in search results'])
        ->and(array_keys($settings))->toBe([
            'title_separator',
            'title_type_post', 'hide_type_post', 'title_type_page', 'hide_type_page',
            'title_home',
            'title_taxonomy_category', 'hide_taxonomy_category', 'title_taxonomy_tag', 'hide_taxonomy_tag',
            'title_author', 'hide_author', 'title_date', 'hide_date',
            'title_search', 'title_not_found',
        ])
        ->and(array_slice($group, 0, 16))->toBe(array_keys($settings))
        ->and($settings['title_separator']->label)->toBe('Separator')
        ->and($settings['title_separator']->maxLength)->toBe(5)
        ->and($settings['title_type_post']->label)->toBe('Title template for posts')
        ->and($settings['title_type_post']->default)->toBe('{title} {sep} {site}')
        ->and($settings['hide_type_page']->label)->toBe('Hide pages from search engines')
        ->and($settings['hide_type_page']->default)->toBeFalse()
        ->and($settings['title_home']->default)->toBe('{site} {sep} {page} {sep} {tagline}')
        ->and($settings['title_taxonomy_category']->label)->toBe('Title template for category archives')
        ->and($settings['hide_taxonomy_tag']->label)->toBe('Hide tag archives from search engines')
        ->and($settings['title_search']->default)->toBe('{title} {sep} {page} {sep} {site}')
        ->and($settings['title_not_found']->label)->toBe('Title template for the “page not found” page')
        ->and($settings['title_not_found']->default)->toBe('{title} {sep} {site}');
});

it('makes rows for a type and a taxonomy registered later, and none for a type that isn\'t public', function (): void {
    typesFresh();
    app(ContentTypeRegistry::class)->register(new ContentType('event', 'Event', 'Events', 'events', supports: ['seo']));
    app(TaxonomyRegistry::class)->register(new Taxonomy('topic', 'Topic', 'Topics', 'topics'));

    $keys = array_map(fn (Setting $setting): string => $setting->key, (new TitlesAndListing)->settings());
    $labels = array_map(fn (Setting $setting): string => $setting->label, (new TitlesAndListing)->settings());

    expect($keys)->toContain('title_type_event', 'hide_type_event', 'title_taxonomy_topic', 'hide_taxonomy_topic')
        ->not->toContain('title_type_region', 'hide_type_region')
        ->and($labels)->toContain('Title template for events', 'Hide topic archives from search engines');
});

it('lets administrators change a template, a hide box and the separator, which its accessors then read; an emptied template reads as its default', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    expect(TitlesAndListing::separator())->toBe('–')
        ->and(TitlesAndListing::template('type:post'))->toBe('{title} {sep} {site}')
        ->and(TitlesAndListing::hidesType('page'))->toBeFalse();

    $this->actingAs($admin)
        ->put('/admin/settings/seo', ['values' => ['title_separator' => ' | ', 'title_type_post' => '{title} {sep} {term}', 'title_home' => '', 'hide_type_page' => true], 'expected_version' => 0])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/seo');

    expect(TitlesAndListing::separator())->toBe('|')
        ->and(TitlesAndListing::template('type:post'))->toBe('{title} {sep} {term}')
        ->and(TitlesAndListing::template('home'))->toBe('{site} {sep} {page} {sep} {tagline}')
        ->and(TitlesAndListing::hidesType('page'))->toBeTrue();
});

it('reads each hide row, and false for a type or taxonomy with no row (I-291)', function (): void {
    cmSettings(['hide_type_page' => true, 'hide_taxonomy_tag' => true, 'hide_author' => true], SearchAndSocial::KEY);

    expect([TitlesAndListing::hidesType('page'), TitlesAndListing::hidesType('post'), TitlesAndListing::hidesType('region'), TitlesAndListing::hidesType('nothing')])->toBe([true, false, false, false])
        ->and([TitlesAndListing::hidesTaxonomy('tag'), TitlesAndListing::hidesTaxonomy('category'), TitlesAndListing::hidesTaxonomy('nothing')])->toBe([true, false, false])
        ->and([TitlesAndListing::hidesAuthors(), TitlesAndListing::hidesDates()])->toBe([true, false]);
});
