<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Comments\Discussion;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\StructuredData\PageAndArticleTypes;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->note = ['key' => 'note', 'singular' => 'Note', 'plural' => 'Notes', 'segment' => 'notes', 'public' => false, 'archive' => false, 'searchable' => false, 'comments' => null];
});

it('gives a stored public type its address pattern, and none to one that isn’t public', function (): void {
    ctStoreRow(ctDefinition(['pattern' => '/watch/{slug}/']));
    ctStoreRow(ctDefinition($this->note));
    app(ContentTypeRegistry::class)->refreshStored();
    $addresses = app(SettingsRegistry::class)->find('addresses');

    expect($addresses?->setting('pattern_film')?->default)->toBe('/watch/{slug}/')
        ->and($addresses?->setting('pattern_note'))->toBeNull()
        ->and(app(SettingsReader::class)->string('addresses', 'pattern_film'))->toBe('/watch/{slug}/')
        ->and(AddressSettings::current()->hasPattern('film'))->toBeTrue();
});

it('leaves a stored type out of Discussion’s list, its new items starting as its own definition says', function (): void {
    ctStoreRow(ctDefinition(['comments' => 'open']));
    app(ContentTypeRegistry::class)->refreshStored();
    $openFor = app(SettingsRegistry::class)->find('discussion')?->setting('open_for');

    expect($openFor?->options)->not->toHaveKey('film')
        ->and($openFor?->default)->not->toContain('film')
        ->and(DiscussionSettings::current()->forNewItem('film'))->toBe(Discussion::Open);
});

it('gives a stored public type its rows in Search and social', function (): void {
    ctStoreRow(ctDefinition());
    app(ContentTypeRegistry::class)->refreshStored();
    $seo = app(SettingsRegistry::class)->find(SearchAndSocial::KEY);

    expect($seo?->setting('title_type_film')?->label)->toBe('Title template for films')
        ->and($seo?->setting('hide_type_film'))->not->toBeNull()
        ->and($seo?->setting(PageAndArticleTypes::pageKey('film'))?->default)->toBe('WebPage');
});

it('builds the groups again when the types change, and not otherwise', function (): void {
    $before = app(SettingsRegistry::class)->find('addresses');

    expect(app(SettingsRegistry::class)->find('addresses'))->toBe($before)
        ->and($before?->setting('pattern_film'))->toBeNull();

    ctStoreRow(ctDefinition());
    app(ContentTypeRegistry::class)->refreshStored();

    expect(app(SettingsRegistry::class)->find('addresses'))->not->toBe($before)
        ->and(app(SettingsRegistry::class)->find('addresses')?->setting('pattern_film'))->not->toBeNull();
});
