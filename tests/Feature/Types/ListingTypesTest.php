<?php

declare(strict_types=1);

use App\Domain\Addresses\ListingTypes;
use App\Domain\Contents\ContentCounts;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Taxonomy\TermCounts;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    ltTypes();
});

it('lists the public types with an archive in the registry’s order, code’s before stored, and leaves out one without', function (): void {
    expect(ListingTypes::withArchive())->toBe(['post', 'film'])
        ->and(ListingTypes::first())->toBe('post');
});

it('lists every public type that uses a taxonomy, with an archive or not, and never one that isn’t public (I-3298)', function (): void {
    ctCreate(null, ['key' => 'note', 'singular' => 'Note', 'plural' => 'Notes', 'segment' => 'notes', 'public' => false, 'archive' => false, 'searchable' => false, 'comments' => null, 'taxonomies' => ['tag']]);

    expect(ListingTypes::usingTaxonomy('category'))->toBe(['post', 'film'])
        ->and(ListingTypes::usingTaxonomy('tag'))->toBe(['post', 'recipe'])
        ->and(ListingTypes::usingTaxonomy('nothing'))->toBe([]);
});

it('puts Posts alone on the home page until Reading says otherwise, offering a made type unticked (I-3298)', function (): void {
    $setting = app(SettingsRegistry::class)->find('reading')?->setting(ListingTypes::SETTING);

    expect(ListingTypes::onHome())->toBe(['post'])
        ->and($setting?->type)->toBe(SettingType::MultiSelect)
        ->and($setting?->label)->toBe('Types on the home page and in the main feed')
        ->and($setting?->options)->toBe(['post' => 'Posts', 'film' => 'Films'])
        ->and($setting?->default)->toBe(['post'])
        ->and($setting?->since)->toBe('0.2.0');
});

it('gives the ticked types in the registry’s order, and leaves out one that lost its archive or is gone', function (): void {
    cmSettings([ListingTypes::SETTING => ['film', 'recipe', 'gone', 'post']], 'reading');

    expect(ListingTypes::onHome())->toBe(['post', 'film']);

    scElevated(fn () => app(UpdateType::class)->execute(new UpdateTypeInput(ctDefinition(['archive' => false]))));

    expect(ListingTypes::onHome())->toBe(['post'])
        ->and(app(SettingsRegistry::class)->find('reading')?->setting(ListingTypes::SETTING)?->options)->toBe(['post' => 'Posts']);
});

it('draws the setting on Reading after Items per page, saves it, and refuses a type it doesn’t offer, writing nothing', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/reading')
        ->assertInertia(fn (Assert $page) => $page
            ->where('fields.0.key', 'posts_per_page')
            ->where('fields.1.key', ListingTypes::SETTING)
            ->where('fields.1.type', 'multiselect')
            ->where('fields.1.options', ['post' => 'Posts', 'film' => 'Films'])
            ->where('values.'.ListingTypes::SETTING, ['post']));

    $this->actingAs($this->admin)->from('/admin/settings/reading')
        ->put('/admin/settings/reading', ['values' => [ListingTypes::SETTING => ['recipe']], 'expected_version' => 0])
        ->assertSessionHasErrors('values.'.ListingTypes::SETTING.'.0');

    expect(ListingTypes::onHome())->toBe(['post']);

    // The same version: the refusal wrote nothing.
    $this->actingAs($this->admin)->from('/admin/settings/reading')
        ->put('/admin/settings/reading', ['values' => [ListingTypes::SETTING => ['post', 'film']], 'expected_version' => 0])
        ->assertSessionHasNoErrors();

    expect(ListingTypes::onHome())->toBe(['post', 'film']);
});

it('sums several types’ published counts in one statement each, for everyone or one author', function (): void {
    $other = User::factory()->create()->assignRole('author');
    $news = typesTerm($this->admin, 'category', 'News');
    ltItem($this->admin, 'post', 'Harbour story', [$news]);
    ltItem($this->admin, 'film', 'Arrival', [$news]);
    ltItem($other, 'film', 'Solaris');
    ltItem($other, 'recipe', 'Fish stew');
    $counts = [];

    $statements = dashQueries(function () use (&$counts, $news): void {
        $counts = [
            ContentCounts::published(['post', 'film']),
            ContentCounts::published(['post', 'film'], (int) $this->admin->id),
            app(TermCounts::class)->published((string) $news->id, 'post', 'film'),
        ];
    });

    expect($counts)->toBe([3, 2, 2])
        ->and($statements)->toHaveCount(3)
        ->and(ContentCounts::published([]))->toBe(0);
});
