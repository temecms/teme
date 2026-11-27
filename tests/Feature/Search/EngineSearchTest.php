<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Search\Operations\Input\ReindexSearchEngineInput;
use App\Domain\Search\Operations\ReindexSearchEngine;
use App\Domain\Search\PostgresSiteSearch;
use App\Domain\Search\SearchEngineSync;
use App\Domain\Search\SearchFeed;
use App\Domain\Site\ListingViews;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    // Booted before the engine was chosen, so the feed subscribes now, as the next request's boot does.
    SearchFeed::listen(app(HookDispatcher::class));
});

it('shows the hits the chosen engine gives', function (): void {
    $id = LanguageSite::version($this->site->editor, 'Ils mangeaient', 'fr', null, [], publish: true);
    Searcher::$answer = fn (SearchRequest $request): SearchHits => new SearchHits([new SearchHit($id, 'engine')], 1, false);

    $hits = app(SiteSearch::class)->search(new SearchRequest('manger', 1, 10, null, 'fr'));

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

it('tags the lists the search draws, so a publish purges the cached search pages', function (): void {
    Searcher::$answer = fn (SearchRequest $request): SearchHits => new SearchHits([], 0, false);

    // The page being drawn, as the cache would note it.
    app(RenderRecord::class)->start(0);
    app(SiteSearch::class)->search(new SearchRequest('manger', 1, 10, ['post']));

    expect(app(RenderRecord::class)->tags())->toContain(PageTags::listOf('post'));
});

it('sends a published item to the engine, and drops one that is unpublished', function (): void {
    $id = LanguageSite::version($this->site->editor, 'Ils mangeaient', 'fr', null, [], publish: true);

    expect(Searcher::$indexed)->toHaveKey($id)
        ->and(Searcher::$indexed[$id])->toBe('Ils mangeaient');

    app(SearchEngineSync::class)->sync('01notanitem0000000000000000');

    expect(Searcher::$removed)->toBe(['01notanitem0000000000000000']);
});

it('sends every published item again on a reindex, through the operation', function (): void {
    $id = LanguageSite::version($this->site->editor, 'Ils mangeaient', 'fr', null, [], publish: true);
    Searcher::$indexed = [];

    app()->instance(Elevation::class, new OperatorElevation);

    app(ReindexSearchEngine::class)->execute(new ReindexSearchEngineInput(null));

    expect(Searcher::$indexed)->toHaveKey($id);
});

it('escapes an engine’s snippet, and lets only a mark through', function (): void {
    $id = LanguageSite::version($this->site->editor, 'Ils mangeaient', 'fr', null, [], publish: true);
    Searcher::$answer = fn (SearchRequest $request): SearchHits => new SearchHits([new SearchHit($id, '<mark>manger</mark> <script>alert(1)</script><b onmouseover="x()">hi</b>')], 1, false);

    $hit = app(SiteSearch::class)->search(new SearchRequest('manger', 1, 10, null, 'fr'))->hits[0];

    expect($hit->snippetHtml)->toBe('<mark>manger</mark> &lt;script&gt;alert(1)&lt;/script&gt;&lt;b onmouseover=&quot;x()&quot;&gt;hi&lt;/b&gt;');
});

it('never shows a password-protected item the engine names', function (): void {
    $id = LanguageSite::version($this->site->editor, 'Ils mangeaient', 'fr', null, [], publish: true);
    DB::table('contents')->where('id', $id)->update(['visibility' => 'protected', 'reader_password' => 'not-the-password-here']);
    Searcher::$answer = fn (SearchRequest $request): SearchHits => new SearchHits([new SearchHit($id, 'engine')], 1, false);

    $hits = app(SiteSearch::class)->search(new SearchRequest('manger', 1, 10, null, 'fr'));

    expect($hits->hits)->toBe([]);
});

it('never shows an item in another language than the one searched', function (): void {
    $id = LanguageSite::version($this->site->editor, 'Hello there', null, null, [], publish: true);
    Searcher::$answer = fn (SearchRequest $request): SearchHits => new SearchHits([new SearchHit($id, 'engine')], 1, false);

    $hits = app(SiteSearch::class)->search(new SearchRequest('hello', 1, 10, null, 'fr'));

    expect($hits->hits)->toBe([]);
});

it('refuses to enable a search engine that did not ask for search.read', function (): void {
    app()->instance(Elevation::class, new OperatorElevation);

    expect(fn () => app(EnablePlugin::class)->execute(new EnablePluginInput('tests/searcher', '1.0.0', [], [], [], null)))
        ->toThrow(ValidationException::class);

    app()->forgetInstance(Elevation::class);
});

it('keeps listening for publishes before an engine is chosen, so one chosen later is kept in step', function (): void {
    // Disabled, the searcher is no engine: listen now, then enable it again.
    app()->instance(Elevation::class, new OperatorElevation);
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/searcher', null));
    app()->forgetInstance(HookDispatcher::class);
    app()->forgetInstance('search.content-listening');
    SearchFeed::listen(app(HookDispatcher::class));

    $manifest = app(PluginRegistry::class)->find('tests/searcher')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/searcher', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);
    app()->forgetInstance(SiteSearch::class);
    Searcher::$indexed = [];

    $id = LanguageSite::version($this->site->editor, 'Ils mangeaient', 'fr', null, [], publish: true);

    expect(Searcher::$indexed)->toHaveKey($id);
});
