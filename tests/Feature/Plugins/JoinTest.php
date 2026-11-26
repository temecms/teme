<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\ListenerFaults;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Plugins\Contributions\Contributions;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Contributions\RecordedStatusFaults;
use App\Domain\Plugins\Contributions\StatusFaults;
use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Faults\SpooledFaults;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Plugins\Queries\PluginRow;
use App\Domain\Plugins\SafeMode;
use App\Domain\Plugins\Seams\ContributionsCheck;
use App\Domain\Plugins\Seams\DispatcherSubscriptions;
use App\Domain\Plugins\Seams\HookSubscriptions;
use App\Domain\Plugins\Seams\PluginContributions;
use App\Domain\Plugins\Seams\PluginServices;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Hooks\HookRefused;
use Tests\Fixtures\Backups\HealthyBackups;
use Tests\Fixtures\Hooks\Pinged;
use Tests\Fixtures\Plugins\Contributor\Contributor;
use Tests\Fixtures\Plugins\Lists\FakeResolver;
use Tests\Fixtures\Plugins\NextProcess;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\ReportedFailures;

uses(RefreshDatabase::class);

/*
 * The join (I-3353): each seam between the hooks, the plugins and their
 * lists wired to the real thing, then proved together, as no part could
 * prove it alone. Each plugin here is enabled through EnablePlugin, started
 * by the loader, and hears hooks that real Operations announce; what it
 * sends leaves through its door to a faked outside service.
 */

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    app()->instance(Elevation::class, new OperatorElevation);

    // Two outside hosts at public addresses; the door never asks DNS here.
    $this->resolver = FakeResolver::answering(['api.example.com' => ['93.184.215.14'], 'other.example.com' => ['93.184.215.16']]);
    app()->instance(HostResolver::class, $this->resolver);

    // Every request a plugin's door sends, with the transaction level and the item's status in the database as it arrived.
    $this->sent = new ArrayObject;
    $this->listen = function (): void {
        Http::preventStrayRequests();
        Http::fake(['*' => function (SentRequest $request): mixed {
            $this->sent[] = [
                'url' => $request->url(),
                'level' => DB::transactionLevel(),
                'status' => Content::query()->withTrashed()->find($request['id'] ?? null)?->status,
                'data' => $request->data(),
            ];

            return Http::response('Told.');
        }]);
    };
    ($this->listen)();

    // Enables a plugin as the operator, agreeing to what its manifest asks for now.
    $this->enable = function (string $name): void {
        // Elevation is scoped: a request the test made has forgotten it.
        app()->instance(Elevation::class, new OperatorElevation);
        $manifest = app(PluginRegistry::class)->all()[$name]->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput($name, $manifest->version(), $manifest->capabilities(), $manifest->hosts(), $manifest->hooks(), null));
    };

    // Teme booted again, as the next request is: the plugins start at boot from the plugins table.
    $this->nextProcess = function (): void {
        // The same server: this process's configuration, the fixtures' plugin folders with it.
        $this->app = NextProcess::boot($this->app, config()->all(), [HostResolver::class => $this->resolver, Elevation::class => new OperatorElevation]);
        $this->withoutVite();
        ($this->listen)();
    };

    $this->publish = function (string $title = 'Harbour news'): Content {
        $item = lifecycleDraft($this->editor, $title);
        listsPublish($item, $this->editor);

        return $item->refresh();
    };

    $this->act = fn (string $operation, Content $item): mixed => app($operation)->execute(new ContentActionInput($item->id, (string) $this->editor->id));

    $this->row = fn (string $name): ?PluginRow => collect(app(PluginList::class)->all())->firstWhere('name', $name);
});

afterEach(fn () => PluginFixtures::forget());

// J1: hooks through the dispatcher.

it('hands each plugin the dispatcher’s own Hooks, without its stopped parts, closed when its register() ends', function (): void {
    $subscriptions = app(HookSubscriptions::class);
    $heard = [];
    $hooks = $subscriptions->for('acme/one', ['content.read'], ['Pinged'], ['acme/one#Pinged#2']);
    $first = $hooks->on(Pinged::class, function (Pinged $ping) use (&$heard): void {
        $heard[] = 'first '.$ping->what;
    });
    $stopped = $hooks->on(Pinged::class, function (Pinged $ping) use (&$heard): void {
        $heard[] = 'stopped '.$ping->what;
    });
    $subscriptions->close($hooks);

    app(HookDispatcher::class)->notify(new Pinged('ping'));

    expect($subscriptions)->toBeInstanceOf(DispatcherSubscriptions::class)
        ->and(app(HookSubscriptions::class))->toBe($subscriptions)
        ->and([$first->id, $stopped->id])->toBe(['acme/one#Pinged#1', 'acme/one#Pinged#2'])
        ->and($heard)->toBe(['first ping'])
        ->and(fn () => $hooks->on(Pinged::class, fn () => null))->toThrow(HookRefused::class, 'It listens for Pinged after it finished starting: subscribe in register().');
});

it('forgets every subscription of one plugin, and no other’s', function (): void {
    $subscriptions = app(HookSubscriptions::class);
    $heard = [];

    foreach (['acme/one', 'acme/two'] as $plugin) {
        $hooks = $subscriptions->for($plugin, ['content.read'], ['Pinged'], []);
        $hooks->on(Pinged::class, function () use (&$heard, $plugin): void {
            $heard[] = $plugin;
        });
        $subscriptions->close($hooks);
    }

    $subscriptions->forget('acme/one');
    app(HookDispatcher::class)->notify(new Pinged('ping'));

    expect($heard)->toBe(['acme/two']);
});

// J2: faults to the spool.

it('sends a listener’s failure to the plugins’ fault spool, a new one each time it is asked for, under its subscription', function (): void {
    $handler = new ReportedFailures(app());
    app()->instance(ExceptionHandler::class, $handler);
    $failure = new RuntimeException('It broke.');
    app(HookDispatcher::class)->subscriber('acme/one', ['content.read'], ['Pinged'])->on(Pinged::class, fn () => throw $failure);

    app(HookDispatcher::class)->notify(new Pinged('ping'));

    expect(app(ListenerFaults::class))->toBeInstanceOf(SpooledFaults::class)
        ->and(app(ListenerFaults::class))->not->toBe(app(ListenerFaults::class))
        ->and($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0][0])->toBe($failure)
        ->and($handler->reported[0][1])->toBe(['plugin' => 'acme/one', 'subscription' => 'acme/one#Pinged#1', 'hook' => Pinged::class]);
});

// J3: doors and contributions.

it('binds the doors, the contributions and their checks, and sends a failed status check to the listener faults', function (): void {
    hkRecordFaults();
    $failure = new RuntimeException('The workspace answered nothing.');

    // As a plugin's checks share it: one adapter, its banner drawn twice in one request.
    $faults = app(StatusFaults::class);
    $faults->report('acme/one', 'acme/one#status', $failure);
    $faults->report('acme/one', 'acme/one#status', $failure);

    expect(app(PluginServices::class))->toBeInstanceOf(PluginDoors::class)
        ->and(app(PluginContributions::class))->toBeInstanceOf(Contributions::class)
        ->and(app(ContributionsCheck::class))->toBeInstanceOf(ContributionsValidator::class)
        ->and(app(StatusFaults::class))->toBeInstanceOf(RecordedStatusFaults::class)
        ->and(hkFaults())->toBe([['owner' => 'acme/one', 'id' => 'acme/one#status', 'hook' => '', 'message' => 'The workspace answered nothing.']]);
});

it('refuses, as the manifest is checked, what a plugin contributes that Teme doesn’t offer', function (): void {
    $copy = PluginFixtures::copy('contributor', ['contributes' => ['pages' => []]]);
    PluginFixtures::use($copy);

    expect(fn () => ($this->enable)('tests/contributor'))->toThrow(ValidationException::class, 'Teme doesn’t offer the pages surface to plugins yet.');
});

// J4: the end-to-end tests.

it('lets an enabled plugin’s listener hear a published post, after the commit', function (): void {
    PluginFixtures::use('contributor');
    app(PluginLoader::class)->load();
    $before = ($this->publish)('Before');

    ($this->enable)('tests/contributor');
    app(PluginLoader::class)->load();
    $base = hkBaseLevel();
    $item = ($this->publish)();

    expect(app(LoadedPlugins::class)->state('tests/contributor'))->toBe(PluginState::Enabled)
        ->and($this->sent->getArrayCopy())->toHaveCount(1)
        ->and($this->sent[0]['url'])->toBe('https://api.example.com/published')
        ->and($this->sent[0]['data']['id'])->toBe($item->id)
        ->and($this->sent[0]['data']['id'])->not->toBe($before->id)
        ->and($this->sent[0]['data']['status'])->toBe('published')
        // Delivered after the outermost commit, reading the published item.
        ->and($this->sent[0]['level'])->toBe($base)
        ->and($this->sent[0]['status'])->toBe('published');
});

/*
 * Replaces the plan's quarantine test (2026-11-11):
 * quarantine is parked (I-3358), so what the first release can still carry
 * of its risk is that the id 5.1a gives a subscription is the id a
 * failure is recorded under.
 */
it('records a plugin listener’s failure under the same id the dispatcher gave its subscription', function (): void {
    PluginFixtures::use('contributor');
    ($this->enable)('tests/contributor');
    app(PluginLoader::class)->load();
    $handler = new ReportedFailures(app());
    app()->instance(ExceptionHandler::class, $handler);
    $plugin = app(LoadedPlugins::class)->running()['tests/contributor'];
    $item = lifecycleDraft($this->editor, 'Harbour news');

    ($this->act)(TrashContent::class, $item);

    expect($plugin)->toBeInstanceOf(Contributor::class)
        ->and(Content::query()->withTrashed()->find($item->id)?->trashed())->toBeTrue()
        ->and(array_map(fn (array $report): array => [$report[0]->getMessage(), $report[1]], $handler->reported))->toBe([[
            'Contributor couldn’t tell its workspace.',
            ['plugin' => 'tests/contributor', 'subscription' => $plugin->subscriptions['ContentTrashed']->id, 'hook' => ContentTrashed::class],
        ]])
        ->and($plugin->subscriptions['ContentTrashed']->id)->toBe('tests/contributor#ContentTrashed#1');
});

it('refuses at enable, and again at subscription, a hook whose capability the plugin lacks, in the same words', function (): void {
    $copy = PluginFixtures::copy('contributor', ['capabilities' => ['http']]);
    PluginFixtures::use($copy);
    $refusedAtEnable = null;

    try {
        ($this->enable)('tests/contributor');
    } catch (ValidationException $refused) {
        $refusedAtEnable = $refused->errors()['plugin'][0];
    }

    // The same plugin's own register(), given its Hooks as the loader gives them, with only what it was granted.
    $manifest = app(PluginRegistry::class)->all()['tests/contributor']->manifest;
    $hooks = app(HookSubscriptions::class)->for('tests/contributor', $manifest->capabilities(), $manifest->hooks(), []);
    $doors = app(PluginServices::class)->for('tests/contributor', 'contributor', $manifest->capabilities(), $manifest->hosts());
    // The class as the loader would load it; no earlier test may have.
    class_exists(Contributor::class) || require_once PluginFixtures::path('contributor').'/src/Contributor.php';
    $plugin = new Contributor(...array_values(array_intersect_key($doors, array_flip(PluginServices::OFFERED))));
    $refusedAtSubscription = null;

    try {
        $plugin->register($hooks);
    } catch (HookRefused $refused) {
        $refusedAtSubscription = $refused->getMessage();
    }

    // Each refusal in its own words (Every word), naming the same hook and the same capability.
    [$hook, $capability] = ['ContentPublished', 'content.read'];

    expect($refusedAtEnable)->toBe("{$hook} needs {$capability}, which it doesn’t ask for.")
        ->and($refusedAtSubscription)->toBe("It listens for {$hook} without asking for {$capability} in its teme-plugin.json.")
        // On the list, the plugin can't be used, and the first reason is the same; the other three hooks need it too.
        ->and(($this->row)('tests/contributor')?->state)->toBe(PluginState::CantBeUsed)
        ->and(($this->row)('tests/contributor')?->reason)->toBe("{$hook} needs {$capability}, which it doesn’t ask for. (and 3 more)")
        ->and(app(HookDispatcher::class)->wants(ContentPublished::class))->toBeFalse();
});

it('sends the plugin’s request only to the hosts its installed manifest asks for and its recorded consent covers', function (array $agreed, array $updated, PluginState $state, array $sent, array $refusals, string $reason): void {
    PluginFixtures::use(PluginFixtures::copy('contributor', ['hosts' => $agreed]));
    ($this->enable)('tests/contributor');

    // The plugin updated in the site's build, its consent as it was.
    PluginFixtures::use(PluginFixtures::copy('contributor', ['version' => '1.1.0', 'hosts' => $updated]));
    app(PluginLoader::class)->load();
    $handler = new ReportedFailures(app());
    app()->instance(ExceptionHandler::class, $handler);

    $item = ($this->publish)();
    ($this->act)(UnpublishContent::class, $item);

    expect(app(LoadedPlugins::class)->state('tests/contributor'))->toBe($state)
        ->and(array_column($this->sent->getArrayCopy(), 'url'))->toBe($sent)
        ->and(array_map(fn (array $report): string => $report[0]->getMessage(), $handler->reported))->toBe($refusals)
        ->and(($this->row)('tests/contributor')?->reason)->toBe($reason);
})->with([
    // Agreed to every example.com host for a version that now asks for one: it reaches that one alone.
    'a consent wider than the manifest' => [['*.example.com'], ['api.example.com'], PluginState::Enabled, ['https://api.example.com/published'], ['It may send requests only to the hosts you agreed to: api.example.com.'], 'Updated from 1.0.0 to 1.1.0; access unchanged.'],
    // A manifest asking for a host nobody agreed to: the plugin doesn't start, so it reaches nothing (D25).
    'a manifest wider than the consent' => [['api.example.com'], ['api.example.com', 'other.example.com'], PluginState::Waiting, [], [], 'Update 1.1.0 is blocked: it asks for more access than you agreed to.'],
]);

it('leaves no listener, settings page, link or banner behind for a plugin that fails to start, or one that is disabled, and keeps its settings’ values', function (): void {
    $admin = $this->admin;
    $sees = function () use ($admin): array {
        $tools = $this->actingAs($admin)->get('/admin/tools')->assertOk()->viewData('page')['props'];
        $before = count($this->sent);
        ($this->publish)('Seen '.uniqid());

        return [
            'settings page' => $this->actingAs($admin)->get('/admin/settings/plugin.contributor')->status(),
            'link' => in_array('Contributor settings', array_column($tools['tools'], 'label'), true),
            'banner' => ($tools['siteStatus'][0] ?? null)['message'] ?? null,
            'heard' => count($this->sent) > $before,
        ];
    };
    // A site where all is well, so the plugin's banner is the one an administrator sees (as StatusBannerTest's).
    HealthyBackups::seed();
    $menu = navCreate($admin);

    foreach (['primary', 'footer'] as $slot) {
        app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $menu->id, (string) $admin->id));
    }

    $everything = ['settings page' => 200, 'link' => true, 'banner' => 'Contributor needs its token.', 'heard' => true];
    $nothing = ['settings page' => 404, 'link' => false, 'banner' => null, 'heard' => false];

    // Enabled and started at boot: everything it adds is there; its project is saved.
    PluginFixtures::use('contributor');
    ($this->enable)('tests/contributor');
    ($this->nextProcess)();
    $enabled = $sees();
    app()->instance(Elevation::class, new OperatorElevation);
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('plugin.contributor', ['project' => 'harbour'], [], [], 0, (string) $admin->id));

    // Disabled: from the next process, nothing it added is left, and its project is kept.
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/contributor', null));
    ($this->nextProcess)();
    $disabled = $sees();

    // Enabled again: its project is as it was saved.
    ($this->enable)('tests/contributor');
    ($this->nextProcess)();
    $again = $sees();
    $project = $this->actingAs($admin)->get('/admin/settings/plugin.contributor')->viewData('page')['props'];

    // A version that fails to start after it subscribed: it is skipped with nothing it added left.
    $broken = PluginFixtures::copy('contributor', ['version' => '1.0.1', 'plugin' => 'Tests\\Fixtures\\Plugins\\Contributor\\BreaksAtStart'], files: ['src/BreaksAtStart.php' => <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Tests\Fixtures\Plugins\Contributor;

        use RuntimeException;
        use Teme\Contracts\Content\ContentPublished;
        use Teme\Contracts\Hooks\Hooks;
        use Teme\Contracts\Http\OutboundHttp;
        use Teme\Contracts\Plugins\Plugin;

        /**
         * Contributor's broken release: it subscribes a listener that would
         * tell its workspace, then fails before its register() ends.
         */
        final class BreaksAtStart implements Plugin
        {
            public function __construct(private readonly OutboundHttp $http) {}

            public function register(Hooks $hooks): void
            {
                $hooks->on(ContentPublished::class, function (ContentPublished $published): void {
                    $this->http->postJson('https://api.example.com/published', ['id' => $published->item->id]);
                });

                throw new RuntimeException('Contributor 1.0.1 broke as it started.');
            }
        }
        PHP]);
    PluginFixtures::use($broken);
    ($this->nextProcess)();
    $failed = $sees();

    expect($enabled)->toBe($everything)
        ->and($disabled)->toBe($nothing)
        ->and(($this->row)('tests/contributor')?->state)->toBe(PluginState::CantBeUsed)
        ->and(($this->row)('tests/contributor')?->reason)->toBe('It failed to start: Contributor 1.0.1 broke as it started.')
        // Its own banner is gone; Teme's says it can't run.
        ->and($failed)->toBe([...$nothing, 'banner' => 'Contributor can’t run: It failed to start: Contributor 1.0.1 broke as it started.'])
        ->and($again)->toBe($everything)
        ->and($project['values']['project'] ?? null)->toBe('harbour');
});

it('starts nothing on a safe page', function (): void {
    PluginFixtures::use('contributor');
    ($this->enable)('tests/contributor');

    PluginFixtures::serving('admin/plugins', fn () => app(PluginLoader::class)->load());
    ($this->publish)('On the Plugins page');

    expect(SafeMode::forPath('admin/plugins'))->toBeTrue()
        ->and(app(LoadedPlugins::class)->running())->toBe([])
        ->and(app(LoadedPlugins::class)->state('tests/contributor'))->toBeNull()
        ->and($this->sent->getArrayCopy())->toBe([]);

    // The page beside it starts the plugin, which then hears the next publish.
    PluginFixtures::serving('admin/posts', fn () => app(PluginLoader::class)->load());
    ($this->publish)('On the Posts page');

    expect(array_keys(app(LoadedPlugins::class)->running()))->toBe(['tests/contributor'])
        ->and(array_column($this->sent->getArrayCopy(), 'url'))->toBe(['https://api.example.com/published']);
});
