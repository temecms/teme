<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Search\Jobs\SyncSearchEntry;
use App\Domain\Search\Operations\Input\ReindexSearchEngineInput;
use App\Domain\Search\Operations\ReindexSearchEngine;
use App\Domain\Search\SearchEngineSync;
use App\Domain\Search\SearchFeed;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    SearchFeed::listen(app(HookDispatcher::class));
});

afterEach(fn () => PluginFixtures::forget());

it('queues the reindex as one job, which queues each next chunk itself', function (): void {
    Bus::fake([SyncSearchEntry::class]);

    app()->instance(Elevation::class, new OperatorElevation);
    app(ReindexSearchEngine::class)->execute(new ReindexSearchEngineInput(null));
    app()->forgetInstance(Elevation::class);

    Bus::assertDispatchedTimes(SyncSearchEntry::class, 1);
});

/**
 * Every published, public item's id, in the order a reindex sends them.
 *
 * @return list<string>
 */
function publishedIds(): array
{
    return DB::table('contents')->where('status', 'published')->whereNull('deleted_at')->where('visibility', 'public')->orderBy('id')->pluck('id')->map(fn (string $id): string => $id)->all();
}

it('sends one chunk, reads each item and its revision in that chunk’s query, and names the next item', function (): void {
    foreach (['Un', 'Deux', 'Trois'] as $title) {
        LanguageSite::version($this->site->editor, $title, 'fr', null, [], publish: true);
    }

    $ids = publishedIds();
    Searcher::$indexed = [];

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $next = app(SearchEngineSync::class)->reindexChunk(null, 2);

    expect($next)->toBe($ids[1])
        ->and(array_keys(Searcher::$indexed))->toBe([$ids[0], $ids[1]])
        ->and(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'content_revisions')))->toHaveCount(1);
});

it('names no next item when the last chunk is sent', function (): void {
    $id = LanguageSite::version($this->site->editor, 'Seul', 'fr', null, [], publish: true);
    Searcher::$indexed = [];

    expect(app(SearchEngineSync::class)->reindexChunk(null, 1000))->toBeNull()
        ->and(Searcher::$indexed)->toHaveKey($id);
});

it('refuses a reindex by someone who may not manage plugins, and queues nothing', function (): void {
    Bus::fake([SyncSearchEntry::class]);
    app()->forgetInstance(Elevation::class);
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => app(ReindexSearchEngine::class)->execute(new ReindexSearchEngineInput((string) $editor->id)))
        ->toThrow(AuthorizationException::class);

    Bus::assertNotDispatched(SyncSearchEntry::class);
});

it('refuses a reindex when no engine is chosen, and queues nothing', function (): void {
    Bus::fake([SyncSearchEntry::class]);
    app()->instance(Elevation::class, new OperatorElevation);
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/searcher', null));

    expect(fn () => app(ReindexSearchEngine::class)->execute(new ReindexSearchEngineInput(null)))
        ->toThrow(ValidationException::class);

    app()->forgetInstance(Elevation::class);

    Bus::assertNotDispatched(SyncSearchEntry::class);
});
