<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/*
 * Links a plugin adds to every feed (docs/plans/plugin-sockets-1.md, A3):
 * values it declares, written by core after the feed's own self link,
 * only while it runs and was granted feeds.links.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    app(PathProjection::class)->syncUser($this->author);
    $this->news = typesTerm($this->editor, 'category', 'News');
    pageArticle($this->author, 'Harbour news', categories: [$this->news]);
    app()->instance(Elevation::class, new OperatorElevation);

    $this->enable = function (): void {
        PluginFixtures::use('hub');
        $manifest = app(PluginRegistry::class)->all()['tests/hub']->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/hub', $manifest->version(), $manifest->capabilities(), $manifest->hosts(), [], null, settingHosts: ['hub.example.com']));
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
    };
    $this->save = fn (array $values, array $agreedHosts = []) => app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.hub', $values, [], [], (int) DB::table('settings')->where('group_key', 'plugin.hub')->value('version'), (string) $this->admin->id, agreedHosts: $agreedHosts));
    $this->links = fn (DOMXPath $feed): array => array_map(
        fn (DOMElement $link): array => [$link->getAttribute('rel'), $link->getAttribute('href')],
        iterator_to_array($feed->query('/rss/channel/atom:link')),
    );
});

afterEach(fn () => PluginFixtures::forget());

it('writes the plugin’s hub link straight after the feed’s own, in the site’s, a term’s and an author’s feed', function (string $uri): void {
    ($this->enable)();
    $self = rtrim(url('/'), '/').$uri;

    expect(($this->links)(feedDom($uri)))->toBe([['self', $self], ['hub', 'https://hub.example.com/']]);
})->with(['/feed/', '/category/news/feed/', '/author/jane-doe/feed/']);

it('gives a link for each address of a urls setting, at most five, each link once, and escapes them', function (): void {
    ($this->enable)();
    ($this->save)(['more_hubs' => implode("\n", [
        'https://hub.example.com/',
        'https://a.example.org/?x=1&y=2',
        'https://b.example.org/',
        'https://c.example.org/',
        'https://d.example.org/',
        'https://e.example.org/',
    ])], ['a.example.org', 'b.example.org', 'c.example.org', 'd.example.org', 'e.example.org']);

    $hrefs = array_column(($this->links)(feedDom('/feed/')), 1);
    $xml = (string) addrGet('/feed/')->getContent();

    expect($hrefs)->toBe([rtrim(url('/'), '/').'/feed/', 'https://hub.example.com/', 'https://a.example.org/?x=1&y=2', 'https://b.example.org/', 'https://c.example.org/', 'https://d.example.org/'])
        ->and($xml)->toContain('<atom:link rel="hub" href="https://a.example.org/?x=1&amp;y=2"/>');
});

it('adds no link for a plugin whose consent doesn’t grant feeds.links, or once it is disabled', function (): void {
    ($this->enable)();
    expect(($this->links)(feedDom('/feed/')))->toHaveCount(2);

    $record = PluginRecord::query()->sole();
    $record->update(['consent' => [...$record->consent, 'capabilities' => ['http']]]);
    app(PluginRecords::class)->changed();
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();

    expect(($this->links)(feedDom('/feed/')))->toHaveCount(1);

    $record->update(['consent' => [...$record->consent, 'capabilities' => ['http', 'feeds.links']]]);
    app(PluginRecords::class)->changed();
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();
    expect(($this->links)(feedDom('/feed/')))->toHaveCount(2);

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hub', null));
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    app(PluginLoader::class)->load();

    expect(($this->links)(feedDom('/feed/')))->toHaveCount(1);
});

it('refuses feed links the manifest may not declare', function (?array $capabilities, ?array $feedLinks, string $words): void {
    $manifest = PluginFixtures::manifest('hub');
    PluginFixtures::use(PluginFixtures::copy('hub', [
        'capabilities' => $capabilities ?? $manifest['capabilities'],
        'contributes' => [...$manifest['contributes'], 'feedLinks' => $feedLinks ?? $manifest['contributes']['feedLinks']],
    ]));

    expect(app(PluginRegistry::class)->find('tests/hub')?->problems)->toContain($words);
})->with([
    'without feeds.links' => [['http'], null, 'It adds links to the feeds, but the plugin doesn’t ask for feeds.links.'],
    'a rel other than hub' => [null, [['rel' => 'self', 'href' => 'https://hub.example.com/']], 'A feed link’s rel must be hub.'],
    'an address that isn’t https' => [null, [['rel' => 'hub', 'href' => 'http://hub.example.com/']], 'A feed link’s href must be an https:// address or one of its url or urls settings, as {key}.'],
    'a setting that isn’t a url' => [null, [['rel' => 'hub', 'href' => '{note}']], 'A feed link’s href must be an https:// address or one of its url or urls settings, as {key}.'],
    'more than two' => [null, array_fill(0, 3, ['rel' => 'hub', 'href' => 'https://hub.example.com/']), 'A plugin can add at most 2 feed links.'],
]);

it('removes stored feeds when the plugin’s settings are saved, once the save commits, and none when it rolls back', function (): void {
    ($this->enable)();
    $folder = cacheOn();

    try {
        addrGet('/feed/')->assertHeader('X-Teme-Cache', 'MISS');
        addrGet('/category/news/feed/')->assertHeader('X-Teme-Cache', 'MISS');

        cacheRolledBack(fn () => ($this->save)(['hub_url' => 'https://hub.example.com/two']));
        expect(cacheKept('/feed/', '/category/news/feed/'))->toBe(['/feed/', '/category/news/feed/']);

        ($this->save)(['hub_url' => 'https://hub.example.com/two']);
        expect(cacheKept('/feed/', '/category/news/feed/'))->toBe([]);
    } finally {
        cacheOff($folder);
    }
});
