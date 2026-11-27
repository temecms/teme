<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Search\PostgresSiteSearch;
use App\Domain\Site\ListingViews;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Search\SearchHit;
use Teme\Contracts\Search\SearchHits;
use Teme\Contracts\Search\SearchRequest;
use Teme\Contracts\Search\SiteSearch;
use Tests\Fixtures\Languages\LanguageSite;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Searcher\Searcher;

require_once dirname(__DIR__, 2).'/Fixtures/plugins/searcher/src/Searcher.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
    Searcher::reset();

    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PluginFixtures::use('searcher');
    $manifest = app(PluginRegistry::class)->find('tests/searcher')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/searcher', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);
    app()->forgetInstance(SiteSearch::class);
});

it('shows the hits the chosen engine gives', function (): void {
    $id = LanguageSite::version($this->site->editor, 'Ils mangeaient', 'fr', null, [], publish: true);
    Searcher::$answer = fn (SearchRequest $request): SearchHits => new SearchHits([new SearchHit($id, 'engine')], 1, false);

    $hits = app(SiteSearch::class)->search(new SearchRequest('manger', 1, 10));

    expect(array_map(fn (SearchHit $hit): string => $hit->contentId, $hits->hits))->toBe([$id]);
});

it('falls back to core’s search when the engine fails', function (): void {
    LanguageSite::version($this->site->editor, 'Ils mangeaient', 'fr', null, [], publish: true);
    Searcher::$answer = function (SearchRequest $request): SearchHits {
        throw new RuntimeException('The engine is down.');
    };

    $fallback = app(SiteSearch::class)->search(new SearchRequest('manger', 1, 10, null, 'fr'));
    $core = app(PostgresSiteSearch::class)->search(new SearchRequest('manger', 1, 10, null, 'fr'));

    expect($fallback->total)->toBe($core->total)
        ->and(array_map(fn (SearchHit $hit): string => $hit->contentId, $fallback->hits))->toBe(array_map(fn (SearchHit $hit): string => $hit->contentId, $core->hits));
});

it('never shows an unpublished item the engine names', function (): void {
    $draft = LanguageSite::version($this->site->editor, 'Brouillon', 'fr', null, [], publish: false);
    Searcher::$answer = fn (SearchRequest $request): SearchHits => new SearchHits([new SearchHit($draft, 'engine')], 1, false);

    $hits = app(SiteSearch::class)->search(new SearchRequest('brouillon', 1, 10));

    expect(app(ListingViews::class)->itemsById(array_map(fn (SearchHit $hit): string => $hit->contentId, $hits->hits)))->toBe([]);
});
