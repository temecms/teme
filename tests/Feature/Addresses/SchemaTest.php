<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettingsGroup;
use App\Domain\Addresses\Models\Path;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('allows each address once', function (): void {
    Path::query()->create(['path' => 'news', 'target_type' => 'term', 'target_id' => '01J0000000000000000000000A', 'is_current' => true]);

    expect(fn () => Path::query()->create(['path' => 'news', 'target_type' => 'content', 'target_id' => '01J0000000000000000000000B', 'is_current' => true]))
        ->toThrow(QueryException::class);
});

it('allows one current address per target, and any number of old ones', function (): void {
    $target = ['target_type' => 'content', 'target_id' => '01J0000000000000000000000C'];
    Path::query()->create(['path' => 'old-one', 'is_current' => false] + $target);
    Path::query()->create(['path' => 'old-two', 'is_current' => false] + $target);
    Path::query()->create(['path' => 'now', 'is_current' => true] + $target);

    expect(fn () => Path::query()->create(['path' => 'also-now', 'is_current' => true] + $target))
        ->toThrow(QueryException::class);
});

it('gives existing users a unique slug from their name', function (): void {
    $a = User::factory()->create(['name' => 'Jane Doe']);
    $b = User::factory()->create(['name' => 'Jane Doe']);

    expect($a->refresh()->slug)->toBe('jane-doe')
        ->and($b->refresh()->slug)->toBe('jane-doe-2');
});

it('declares the addresses settings with WordPress-like defaults', function (): void {
    $settings = app(SettingsReader::class);

    expect($settings->string('addresses', 'pattern_post'))->toBe('/{slug}/')
        ->and($settings->string('addresses', 'pattern_page'))->toBe('/{parent}/{slug}/')
        ->and($settings->string('addresses', 'base_category'))->toBe('category')
        ->and($settings->string('addresses', 'front_shows'))->toBe('latest');
});

it('gives every public type a pattern and every public taxonomy a base, from the registries', function (): void {
    typesFresh();
    app(ContentTypeRegistry::class)->register(new ContentType('event', 'Event', 'Events', 'events'));
    app(ContentTypeRegistry::class)->register(new ContentType('notice', 'Notice', 'Notices', 'notices', public: false));

    $defaults = collect(AddressSettingsGroup::build(app(ContentTypeRegistry::class), app(TaxonomyRegistry::class))->settings)->pluck('default', 'key');

    expect($defaults['pattern_event'])->toBe('/events/{slug}/')
        ->and($defaults['base_tag'])->toBe('tag')
        ->and($defaults->has('pattern_notice'))->toBeFalse();
});

it('refuses to change the addresses group through the generic settings screen', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    expect(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('addresses', ['base_tag' => 'topic'], [], [], 0, (string) $admin->id)))
        ->toThrow(ValidationException::class);
});
