<?php

declare(strict_types=1);

use App\Domain\Plugins\Columns\PluginColumns;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Preferences\Operations\Input\UpdatePreferencesInput;
use App\Domain\Preferences\Operations\UpdatePreferences;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $this->author = User::factory()->create()->assignRole('author');

    $this->enable = function (string $folder = 'columns'): void {
        PluginFixtures::use($folder);
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        $manifest = app(PluginRegistry::class)->all()['tests/'.$folder]->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput(
            'tests/'.$folder,
            $manifest->version(),
            $manifest->capabilities(),
            $manifest->hosts(),
            $manifest->hooks(),
            null,
        ));
        app(PluginLoader::class)->load();
    };
});

afterEach(fn () => PluginFixtures::forget());

it('fails to start a plugin that declares a column it has no handler for', function (): void {
    $copy = PluginFixtures::copy('columns', [
        'plugin' => 'Tests\\Fixtures\\Plugins\\Columns\\MissingNote',
    ], files: [
        'src/MissingNote.php' => <<<'PHP'
<?php
namespace Tests\Fixtures\Plugins\Columns;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Plugins\Plugin;
final class MissingNote implements HasListColumns, Plugin {
    public function register(Hooks $hooks): void {}
    public function listColumns(): array { return ['score' => new ScoreColumn('answer'), 'views' => new ViewsColumn('answer')]; }
}
PHP,
    ]);
    PluginFixtures::use($copy);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $manifest = app(PluginRegistry::class)->all()['tests/columns']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/columns', $manifest->version(), $manifest->capabilities(), [], [], null));
    app(PluginLoader::class)->load();

    expect(app(LoadedPlugins::class)->failure('tests/columns'))
        ->toBe('It declares column "note" but has no handler for it.')
        ->and(app(PluginColumns::class)->for('post'))->toBe([]);
});

it('fails to start a plugin that has a handler for a column it doesn\'t declare', function (): void {
    $copy = PluginFixtures::copy('columns', [
        'plugin' => 'Tests\\Fixtures\\Plugins\\Columns\\ExtraHandler',
    ], files: [
        'src/ExtraHandler.php' => <<<'PHP'
<?php
namespace Tests\Fixtures\Plugins\Columns;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Plugins\Plugin;
final class ExtraHandler implements HasListColumns, Plugin {
    public function register(Hooks $hooks): void {}
    public function listColumns(): array {
        return [
            'score' => new ScoreColumn('answer'),
            'views' => new ViewsColumn('answer'),
            'note' => new NoteColumn('answer'),
            'extra' => new NoteColumn('answer'),
        ];
    }
}
PHP,
    ]);
    PluginFixtures::use($copy);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $manifest = app(PluginRegistry::class)->all()['tests/columns']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/columns', $manifest->version(), $manifest->capabilities(), [], [], null));
    app(PluginLoader::class)->load();

    expect(app(LoadedPlugins::class)->failure('tests/columns'))
        ->toBe('It has a handler for column "extra", which isn’t declared.')
        ->and(app(PluginColumns::class)->for('post'))->toBe([]);
});

it('fails to start a plugin that declares columns but doesn\'t implement HasListColumns', function (): void {
    $copy = PluginFixtures::copy('columns', [
        'plugin' => 'Tests\\Fixtures\\Plugins\\Columns\\NoInterface',
    ], files: [
        'src/NoInterface.php' => <<<'PHP'
<?php
namespace Tests\Fixtures\Plugins\Columns;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;
final class NoInterface implements Plugin {
    public function register(Hooks $hooks): void {}
}
PHP,
    ]);
    PluginFixtures::use($copy);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $manifest = app(PluginRegistry::class)->all()['tests/columns']->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/columns', $manifest->version(), $manifest->capabilities(), [], [], null));
    app(PluginLoader::class)->load();

    expect(app(LoadedPlugins::class)->failure('tests/columns'))
        ->toBe('It declares columns but doesn’t implement Teme\Contracts\Lists\HasListColumns.')
        ->and(app(PluginColumns::class)->for('post'))->toBe([]);
});

it("offers a running plugin's columns for the types it names", function (): void {
    ($this->enable)();

    $post = app(PluginColumns::class)->for('post');
    $page = app(PluginColumns::class)->for('page');

    expect(array_map(fn ($c) => [$c->key, $c->width], $post))->toBe([
        ['plugin:columns:score', 100],
        ['plugin:columns:views', 100],
        ['plugin:columns:note', 168],
    ])->and(array_map(fn ($c) => $c->key, $page))->toBe([
        'plugin:columns:score',
        'plugin:columns:note',
    ]);
});

it('offers nothing from a disabled plugin, or from one whose consent lacks content.read', function (): void {
    ($this->enable)();
    expect(app(PluginColumns::class)->for('post'))->not->toBeEmpty();

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/columns', null));
    app(PluginLoader::class)->load();
    expect(app(PluginColumns::class)->for('post'))->toBe([]);

    ($this->enable)();
    $record = PluginRecord::query()->where('name', 'tests/columns')->sole();
    $consent = $record->consent;
    $consent['capabilities'] = [];
    $record->consent = $consent;
    $record->save();
    app(PluginRecords::class)->changed();

    expect(app(PluginColumns::class)->for('post'))->toBe([]);
});

it('saves a person\'s columns with a plugin column, through UpdatePreferences', function (): void {
    ($this->enable)();

    app(UpdatePreferences::class)->execute(new UpdatePreferencesInput([
        'lists.post.columns' => ['author', 'plugin:columns:score', 'date'],
    ], (string) $this->author->id));

    expect(PreferencesFor::user($this->author->refresh())['lists.post.columns'])
        ->toBe(['author', 'plugin:columns:score', 'date']);
});

it('refuses a plugin column that isn\'t offered', function (): void {
    ($this->enable)();
    $before = PreferencesFor::user($this->author);

    expect(fn () => app(UpdatePreferences::class)->execute(new UpdatePreferencesInput([
        'lists.post.columns' => ['plugin:columns:nope'],
    ], (string) $this->author->id)))->toThrow(ValidationException::class);

    expect(fn () => app(UpdatePreferences::class)->execute(new UpdatePreferencesInput([
        'lists.page.columns' => ['plugin:columns:views'],
    ], (string) $this->author->id)))->toThrow(ValidationException::class);

    expect(PreferencesFor::user($this->author->refresh()))->toBe($before);
});

it('drops a disabled plugin\'s column from a person\'s columns and keeps the rest', function (): void {
    ($this->enable)();
    app(UpdatePreferences::class)->execute(new UpdatePreferencesInput([
        'lists.post.columns' => ['author', 'plugin:columns:score', 'date'],
    ], (string) $this->author->id));

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/columns', null));
    app(PluginLoader::class)->load();

    expect(PreferencesFor::user($this->author->refresh())['lists.post.columns'])
        ->toBe(['author', 'date']);
});

it('never shows a plugin column by default', function (): void {
    ($this->enable)();

    $default = PreferencesFor::user($this->author)['lists.post.columns'];

    expect($default)->not->toContain('plugin:columns:score')
        ->and(collect($default)->filter(fn (string $id): bool => str_starts_with($id, 'plugin:'))->all())->toBe([]);
});
