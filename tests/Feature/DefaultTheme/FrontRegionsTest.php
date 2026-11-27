<?php

/**
 * The default theme draws front-top and front-sections only on a static front
 * page, not on other pages and not when the front shows the latest posts.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Themes\ThemeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('declares front-top and front-sections with their labels and limits', function (): void {
    $regions = app(ThemeRegistry::class)->find('default')->manifest->regions();

    expect($regions)->toHaveKeys(['after-content', 'front-top', 'front-sections'])
        ->and($regions['front-top']['label'])->toBe('Front page top')
        ->and($regions['front-top']['max'])->toBe(3)
        ->and($regions['front-sections']['label'])->toBe('Front page sections')
        ->and($regions['front-sections']['max'])->toBe(8);
});

it('draws the front regions on a static front page only', function (): void {
    $home = addrPublished($this->author, 'Welcome home', 'page');
    $about = addrPublished($this->author, 'About us', 'page');
    $posts = addrPublished($this->author, 'News', 'page');
    pageArticle($this->author, 'A harbour note');

    $top = regionCreate($this->author, 'default', 'front-top');
    regionSave($top, $this->author, [docText('Top of the front')]);
    listsPublish($top, $this->author);

    $sections = regionCreate($this->author, 'default', 'front-sections');
    regionSave($sections, $this->author, [docText('Sections on the front')]);
    listsPublish($sections, $this->author);

    addrChange($this->admin, [
        'frontShows' => AddressSettings::FRONT_PAGE,
        'frontPageId' => $home->id,
        'postsPageId' => $posts->id,
    ]);

    $front = addrGet('/');
    $front->assertOk()->assertHeader('X-Teme-Template', 'theme::templates.single-page');
    expect($front->getContent())
        ->toContain('data-region="front-top"')
        ->toContain('Top of the front')
        ->toContain('data-region="front-sections"')
        ->toContain('Sections on the front');

    expect(addrGet('/about-us/')->getContent())
        ->not->toContain('data-region="front-top"')
        ->not->toContain('data-region="front-sections"')
        ->not->toContain('Top of the front');

    expect(addrGet('/news/')->getContent())
        ->not->toContain('data-region="front-top"')
        ->not->toContain('data-region="front-sections"');
});

it('draws no front regions when the front shows the latest posts', function (): void {
    pageArticle($this->author, 'Latest on the quay');

    $top = regionCreate($this->author, 'default', 'front-top');
    regionSave($top, $this->author, [docText('Should stay off latest')]);
    listsPublish($top, $this->author);

    addrChange($this->admin, ['frontShows' => AddressSettings::FRONT_LATEST]);

    $html = addrGet('/')->assertOk()->getContent();

    expect($html)
        ->not->toContain('data-region="front-top"')
        ->not->toContain('data-region="front-sections"')
        ->not->toContain('Should stay off latest');
});
