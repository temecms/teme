<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Queries\PluginList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Hooks\HookRefused;
use Teme\Contracts\Hooks\HookSender;
use Tests\Fixtures\Hooks\Pinged;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Relay\Hooks\ArticleTitle;
use Tests\Fixtures\Plugins\Relay\Hooks\ArticleWritten;
use Tests\Fixtures\Plugins\Relay\Hooks\PromptModules;
use Tests\Fixtures\Plugins\Relay\RelayPlugin;
use Tests\Fixtures\Plugins\RelayPro\RelayProPlugin;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PluginFixtures::loadFromOriginal('relay');
    /** Enables a fixture plugin, or a copy of one, as EnablePlugin leaves it. */
    $this->enable = function (string $folder, ?string $path = null): PluginRecord {
        $manifest = json_decode((string) file_get_contents(($path ?? PluginFixtures::path($folder)).'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);

        return PluginRecord::factory()->enabled()->create([
            'name' => $manifest['name'],
            'code' => $manifest['code'],
            'version' => $manifest['version'],
            'consent' => PluginFixtures::agreed($manifest),
        ]);
    };

    $this->start = function (string ...$folders): void {
        PluginFixtures::use(...$folders);

        foreach ($folders as $folder) {
            ($this->enable)(basename($folder), str_starts_with($folder, '/') ? $folder : null);
        }

        app(PluginLoader::class)->load();
    };

    $this->sender = function (): HookSender {
        $relay = app(LoadedPlugins::class)->running()['tests/relay'] ?? null;

        expect($relay)->toBeInstanceOf(RelayPlugin::class, (string) app(LoadedPlugins::class)->failure('tests/relay'));

        return $relay->hooks;
    };

    $this->pro = function (): RelayProPlugin {
        $pro = app(LoadedPlugins::class)->running()['tests/relay-pro'] ?? null;

        expect($pro)->toBeInstanceOf(RelayProPlugin::class, (string) app(LoadedPlugins::class)->failure('tests/relay-pro'));

        return $pro;
    };
});

afterEach(fn () => PluginFixtures::forget());

it('lets a plugin that requires another hear each of its hooks', function (): void {
    hkRecordFaults();
    ($this->start)('relay', 'relay-pro');

    $sender = ($this->sender)();

    expect($sender->collect(new PromptModules('travel')))->toBe(['pro:tone', 'pro:travel'])
        ->and($sender->transform(new ArticleTitle('Lisbon in a day')))->toBe('Lisbon in a day (Pro)');

    $sender->notify(new ArticleWritten('Lisbon in a day'));

    expect(($this->pro)()->written)->toBe(['Lisbon in a day'])
        ->and(hkFaults())->toBe([]);
});

it('delivers a notification sent inside a transaction after it commits, and never after a rollback', function (): void {
    ($this->start)('relay', 'relay-pro');
    $sender = ($this->sender)();
    $pro = ($this->pro)();

    DB::transaction(function () use ($sender, $pro): void {
        $sender->notify(new ArticleWritten('Kept'));

        expect($pro->written)->toBe([]);
    });

    try {
        DB::transaction(function () use ($sender): void {
            $sender->notify(new ArticleWritten('Rolled back'));

            throw new RuntimeException('Undo.');
        });
    } catch (RuntimeException) {
    }

    expect($pro->written)->toBe(['Kept']);
});

it('records a listener’s failure under the plugin that listens, and the rest go on', function (): void {
    hkRecordFaults();
    ($this->start)('relay', 'relay-pro');

    expect(($this->sender)()->collect(new PromptModules('fail')))->toBe(['pro:fail'])
        ->and(hkFaults())->toBe([[
            'owner' => 'tests/relay-pro',
            'id' => 'tests/relay-pro#relay:PromptModules#1',
            'hook' => PromptModules::class,
            'message' => 'Relay Pro couldn’t build its modules.',
        ]]);
});

it('refuses to send a hook the plugin doesn’t declare', function (): void {
    ($this->start)('relay', 'relay-pro');
    $sender = ($this->sender)();

    expect(fn () => $sender->notify(new Pinged('hello')))
        ->toThrow(HookRefused::class, 'Relay sends Pinged, which its teme-plugin.json doesn’t declare under contributes.hooks.');
});

it('refuses to start a plugin whose declared hook isn’t one it may send', function (array $added, array $manifest, array $files, string $why, array $psr4 = [], array $alongside = []): void {
    $copy = PluginFixtures::copy('relay', [
        ...$manifest,
        'contributes' => ['hooks' => [...PluginFixtures::manifest('relay')['contributes']['hooks'], ...$added]],
    ], $psr4 === [] ? [] : ['autoload' => ['psr-4' => ['Tests\\Fixtures\\Plugins\\Relay\\' => 'src/', ...$psr4]]], $files);

    // A namespace the plugin claims loads from its own folder, whichever test first registered its autoloader.
    foreach ($psr4 === [] ? [] : array_keys($files) as $file) {
        require_once $copy.'/'.$file;
    }

    ($this->start)($copy, ...$alongside);

    expect(app(LoadedPlugins::class)->running())->not->toHaveKey('tests/relay')
        ->and(app(LoadedPlugins::class)->failure('tests/relay'))->toBe($why);
})->with([
    'a gate' => [
        [['name' => 'StopArticle', 'class' => 'Tests\\Fixtures\\Plugins\\Relay\\Hooks\\StopArticle', 'description' => 'Stops an article.']],
        [],
        ['src/Hooks/StopArticle.php' => "<?php\nnamespace Tests\\Fixtures\\Plugins\\Relay\\Hooks;\nuse Teme\\Contracts\\Hooks\\Gate;\nuse Teme\\Contracts\\Hooks\\HookContract;\n#[HookContract]\nfinal class StopArticle implements Gate {}\n"],
        'Its hook StopArticle names Tests\\Fixtures\\Plugins\\Relay\\Hooks\\StopArticle, which isn’t a Notification, Transform or Collect hook.',
    ],
    'a class that is no hook' => [
        [['name' => 'Plain', 'class' => 'Tests\\Fixtures\\Plugins\\Relay\\RelayPlugin', 'description' => 'Not a hook.']],
        [],
        [],
        'Its hook Plain names Tests\\Fixtures\\Plugins\\Relay\\RelayPlugin, which isn’t a Notification, Transform or Collect hook.',
    ],
    'another namespace' => [
        [['name' => 'Saved', 'class' => 'Teme\\Contracts\\Content\\ContentSaved', 'description' => 'Core’s own hook.']],
        [],
        [],
        'Its hook Saved names Teme\\Contracts\\Content\\ContentSaved, which isn’t in the plugin’s own namespace.',
    ],
    'a capability it doesn’t ask for' => [
        [],
        ['capabilities' => ['notices.send']],
        [],
        'Its hook DraftShared names Tests\\Fixtures\\Plugins\\Relay\\Hooks\\DraftShared, which needs content.read, which the plugin doesn’t ask for.',
    ],
    'core’s namespace, claimed in its composer.json' => [
        [['name' => 'Saved', 'class' => 'Teme\\Contracts\\Content\\ContentSaved', 'description' => 'Core’s own hook.']],
        [],
        [],
        'Its hook Saved names Teme\\Contracts\\Content\\ContentSaved, which isn’t in the plugin’s own namespace.',
        ['Teme\\Contracts\\' => 'src/'],
    ],
    'another plugin’s namespace, claimed in its composer.json' => [
        [['name' => 'Borrowed', 'class' => 'Tests\\Fixtures\\Plugins\\RelayPro\\Hooks\\Borrowed', 'description' => 'Another plugin’s hook.']],
        [],
        ['src/Hooks/Borrowed.php' => "<?php\nnamespace Tests\\Fixtures\\Plugins\\RelayPro\\Hooks;\nuse Teme\\Contracts\\Hooks\\Notification;\nuse Teme\\Contracts\\Hooks\\HookContract;\n#[HookContract]\nfinal class Borrowed implements Notification {}\n"],
        'Its hook Borrowed names Tests\\Fixtures\\Plugins\\RelayPro\\Hooks\\Borrowed, which isn’t in the plugin’s own namespace.',
        ['Tests\\Fixtures\\Plugins\\RelayPro\\' => 'src/'],
        ['relay-pro'],
    ],
]);

it('refuses a listener without the capability the declaring plugin’s hook needs', function (): void {
    $copy = PluginFixtures::copy('relay-pro', ['plugin' => 'Tests\\Fixtures\\Plugins\\RelayPro\\RelayProReader', 'hooks' => ['relay:DraftShared']]);

    ($this->start)('relay', $copy);

    expect(app(LoadedPlugins::class)->running())->toHaveKey('tests/relay')
        ->not->toHaveKey('tests/relay-pro')
        ->and(app(LoadedPlugins::class)->failure('tests/relay-pro'))->toBe('It listens for relay:DraftShared without asking for content.read in its teme-plugin.json.');
});

it('refuses a listener that doesn’t list the declaring plugin under requires.plugins', function (): void {
    $copy = PluginFixtures::copy('relay-pro', ['requires' => ['teme' => '^0.5']]);

    ($this->start)('relay', $copy);

    $row = collect(app(PluginList::class)->all())->firstWhere('name', 'tests/relay-pro');

    expect(app(LoadedPlugins::class)->running())->not->toHaveKey('tests/relay-pro')
        ->and(app(HookDispatcher::class)->wants(PromptModules::class))->toBeFalse()
        ->and($row?->state)->toBe(PluginState::CantBeUsed)
        ->and($row?->reason)->toBe('It listens to relay:PromptModules, but doesn’t list tests/relay under requires.plugins. (and 2 more)');
});
