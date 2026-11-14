<?php

declare(strict_types=1);

use App\Domain\Seo\Queries\SearchAndSocialScreen;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Settings\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    ltTypes();
});

it('gives Films’ archive page a title row and a hide box after the home page’s, and none to Posts or Recipes (I-853)', function (): void {
    $rows = (new TitlesAndListing)->rows();
    $keys = array_column($rows, 'key');
    $at = array_search('archive:film', $keys, true);

    expect($at)->toBe(array_search('home', $keys, true) + 1)
        ->and($rows[(int) $at])->toBe(['key' => 'archive:film', 'label' => 'Films archive page', 'template' => 'title_archive_film', 'default' => TitlesAndListing::ARCHIVE, 'hide' => 'hide_archive_film'])
        ->and($keys)->not->toContain('archive:post')
        ->and($keys)->not->toContain('archive:recipe');
});

it('declares the row’s template and hide box in Search and social, with their words', function (): void {
    $group = app(SettingsRegistry::class)->find(SearchAndSocial::KEY);

    expect($group?->setting('title_archive_film')?->label)->toBe('Title template for the films archive page')
        ->and($group?->setting('title_archive_film')?->description)->toBe('The title of the page listing every published film. {title} is “Films”.')
        ->and($group?->setting('title_archive_film')?->default)->toBe(TitlesAndListing::ARCHIVE)
        ->and($group?->setting('title_archive_film')?->since)->toBe('0.2.0')
        ->and($group?->setting('hide_archive_film')?->label)->toBe('Hide the films archive page from search engines')
        ->and($group?->setting('hide_archive_film')?->description)->toBe('Marks it noindex and leaves it out of the sitemap. Each film follows its own row.')
        ->and($group?->setting('title_archive_post'))->toBeNull();
});

it('fills the row’s example on the Search and social screen with the type’s plural', function (): void {
    $rows = collect(app(SearchAndSocialScreen::class)->data($this->admin)['titles']['rows'])->keyBy('key');

    expect($rows['archive:film']['tokens']['title'])->toBe('Films')
        ->and($rows['archive:film']['note'])->toBeNull();
});
