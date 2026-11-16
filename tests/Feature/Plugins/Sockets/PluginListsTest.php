<?php

declare(strict_types=1);

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Lists\ListTooSlow;
use App\Domain\Plugins\Lists\PluginLists;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\PluginList;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Workbench\LogList;
use Tests\Fixtures\Plugins\Workbench\Workbench;

uses(RefreshDatabase::class);

// The fixture's classes, loaded before a test sets what its list does.
foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/workbench/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $this->admin = User::factory()->create()->assignRole('administrator');
    Workbench::reset();

    $this->faults = new class implements ListenerFaults
    {
        /** @var list<array{string, string, string, Throwable}> */
        public array $seen = [];

        public function record(string $owner, string $id, string $hook, Throwable $failure): void
        {
            $this->seen[] = [$owner, $id, $hook, $failure];
        }
    };
    app()->instance(ListenerFaults::class, $this->faults);

    $this->clock = new class implements ColumnClock
    {
        public int $now = 0;

        /** @var list<int> */
        public array $steps = [];

        public function nanoseconds(): int
        {
            if ($this->steps !== []) {
                $this->now += array_shift($this->steps);
            }

            return $this->now;
        }
    };
    app()->instance(ColumnClock::class, $this->clock);

    $this->enable = function (string $folder = 'workbench'): void {
        PluginFixtures::use($folder);
        $manifest = app(PluginRegistry::class)->find('tests/workbench')?->manifest;
        app(EnablePlugin::class)->execute(new EnablePluginInput('tests/workbench', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], (string) $this->admin->id));
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        app(PluginLoader::class)->load();
    };

    $this->manifestErrors = fn (array $lists): array => app(ContributionsValidator::class)->errors(['lists' => $lists], '1.0.0');
});

afterEach(function (): void {
    Workbench::reset();
    PluginFixtures::forget();
});

it('gives the settings page each list with its columns and cleaned rows', function (): void {
    ($this->enable)();
    Workbench::$rows = fn (int $limit): array => [
        ['address' => Cell::text("https://example.com/\u{202E}moc\n  page"), 'sent' => Cell::number(12), 'answer' => Cell::badge('Accepted', 'ok')],
        ['address' => Cell::text(str_repeat('a', 120))],
    ];

    $this->actingAs($this->admin)->get(route('settings.show', ['group' => 'plugin.workbench']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('pluginLists', 1)
            ->where('pluginLists.0.id', 'log')
            ->where('pluginLists.0.label', 'Recent pings')
            ->where('pluginLists.0.description', 'The last pings sent.')
            ->where('pluginLists.0.columns', [
                ['id' => 'address', 'label' => 'Address', 'kind' => 'text'],
                ['id' => 'sent', 'label' => 'Sent', 'kind' => 'number'],
                ['id' => 'answer', 'label' => 'Answer', 'kind' => 'badge'],
            ])
            ->where('pluginLists.0.failed', null)
            ->where('pluginLists.0.rows.0.address', ['kind' => 'text', 'value' => 'https://example.com/ moc page'])
            ->where('pluginLists.0.rows.0.sent', ['kind' => 'number', 'value' => 12])
            ->where('pluginLists.0.rows.0.answer', ['kind' => 'badge', 'value' => 'Accepted', 'tone' => 'ok'])
            ->where('pluginLists.0.rows.1.address.value', str_repeat('a', 79).'…')
            ->missing('pluginLists.0.rows.1.sent'));

    expect(Workbench::$asked)->toBe([3]);
});

it('keeps markup as text', function (): void {
    ($this->enable)();
    Workbench::$rows = fn (int $limit): array => [['address' => Cell::text('<script>alert(1)</script><b>bold</b>')]];

    $lists = app(PluginLists::class)->forGroup('plugin.workbench');

    expect($lists[0]['rows'][0]['address']['value'])->toBe('<script>alert(1)</script><b>bold</b>');
});

it('shows at most the declared rows, and records a list that gives more', function (): void {
    ($this->enable)();
    Workbench::$rows = fn (int $limit): array => array_fill(0, 5, ['sent' => Cell::number(1)]);

    $lists = app(PluginLists::class)->forGroup('plugin.workbench');

    expect($lists[0]['rows'])->toHaveCount(3)
        ->and($this->faults->seen)->toHaveCount(1);
});

it('shows the failure line and records a list that throws', function (): void {
    ($this->enable)();
    Workbench::$rows = fn (int $limit): array => throw new RuntimeException('The log is locked.');

    $lists = app(PluginLists::class)->forGroup('plugin.workbench');

    expect($lists[0]['failed'])->toBe('This list couldn’t be shown this time.')
        ->and($lists[0]['rows'])->toBe([])
        ->and($this->faults->seen[0][0])->toBe('tests/workbench')
        ->and($this->faults->seen[0][1])->toBe('tests/workbench#list:log')
        ->and($this->faults->seen[0][2])->toBe(PluginList::class)
        ->and($this->faults->seen[0][3]->getMessage())->toBe('The log is locked.');
});

it('shows the failure line and records a list slower than 100 ms', function (): void {
    ($this->enable)();
    $this->clock->steps = [0, 101_000_000];

    $lists = app(PluginLists::class)->forGroup('plugin.workbench');

    expect($lists[0]['failed'])->toBe('This list couldn’t be shown this time.')
        ->and($this->faults->seen[0][3])->toBeInstanceOf(ListTooSlow::class);
});

it('shows a list within 100 ms', function (): void {
    ($this->enable)();
    $this->clock->steps = [0, 99_000_000];

    $lists = app(PluginLists::class)->forGroup('plugin.workbench');

    expect($lists[0]['failed'])->toBeNull()
        ->and($this->faults->seen)->toBe([]);
});

it('asks no list once the page has spent 250 ms', function (): void {
    $manifest = PluginFixtures::manifest('workbench');
    $manifest['contributes']['lists'][] = ['id' => 'later', 'label' => 'Later', 'columns' => [['id' => 'sent', 'label' => 'Sent', 'kind' => 'number']]];
    Workbench::$lists = ['log' => new LogList, 'later' => new LogList];
    ($this->enable)(PluginFixtures::copy('workbench', ['contributes' => $manifest['contributes']]));
    $this->clock->steps = [0, 260_000_000];

    $lists = app(PluginLists::class)->forGroup('plugin.workbench');

    expect($lists)->toHaveCount(2)
        ->and($lists[1]['id'])->toBe('later')
        ->and($lists[1]['failed'])->toBe('This list couldn’t be shown this time.')
        ->and(Workbench::$asked)->toBe([3]);
});

it('gives no lists for a group that isn’t a running plugin’s', function (): void {
    ($this->enable)();

    expect(app(PluginLists::class)->forGroup('general'))->toBe([])
        ->and(app(PluginLists::class)->forGroup('plugin.columns'))->toBe([]);
});

it('refuses lists the manifest can’t declare', function (array $lists, string $error): void {
    expect(($this->manifestErrors)($lists))->toContain($error);
})->with([
    'three lists' => [array_fill(0, 3, ['id' => 'log', 'label' => 'Log', 'columns' => [['id' => 'at', 'label' => 'At', 'kind' => 'text']]]), 'A plugin can add at most 2 lists.'],
    'seven columns' => [[['id' => 'log', 'label' => 'Log', 'columns' => array_map(fn (int $n): array => ['id' => "c{$n}", 'label' => "C{$n}", 'kind' => 'text'], range(1, 7))]], 'A list needs 1 to 6 columns.'],
    'no columns' => [[['id' => 'log', 'label' => 'Log', 'columns' => []]], 'A list needs 1 to 6 columns.'],
    'fifty-one rows' => [[['id' => 'log', 'label' => 'Log', 'rows' => 51, 'columns' => [['id' => 'at', 'label' => 'At', 'kind' => 'text']]]], 'A list shows 1 to 50 rows.'],
    'no rows' => [[['id' => 'log', 'label' => 'Log', 'rows' => 0, 'columns' => [['id' => 'at', 'label' => 'At', 'kind' => 'text']]]], 'A list shows 1 to 50 rows.'],
    'an unknown kind' => [[['id' => 'log', 'label' => 'Log', 'columns' => [['id' => 'at', 'label' => 'At', 'kind' => 'time']]]], 'A column’s kind must be text, number or badge.'],
    'no label' => [[['id' => 'log', 'columns' => [['id' => 'at', 'label' => 'At', 'kind' => 'text']]]], 'A list needs a label.'],
    'a bad id' => [[['id' => 'Log!', 'label' => 'Log', 'columns' => [['id' => 'at', 'label' => 'At', 'kind' => 'text']]]], 'A list’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.'],
]);

it('accepts two lists of six columns and fifty rows', function (): void {
    $list = fn (string $id): array => ['id' => $id, 'label' => 'Log', 'rows' => 50, 'columns' => array_map(fn (int $n): array => ['id' => "c{$n}", 'label' => "C{$n}", 'kind' => 'badge'], range(1, 6))];

    expect(($this->manifestErrors)([$list('one'), $list('two')]))->toBe([]);
});

it('doesn’t start a plugin whose declared list has no class, or whose class has no declared list', function (array $ids, string $error): void {
    Workbench::$lists = array_fill_keys($ids, new LogList);
    ($this->enable)();

    expect(app(LoadedPlugins::class)->failure('tests/workbench'))->toBe($error);
})->with([
    'missing' => [[], 'It declares list "log" but has no class for it.'],
    'undeclared' => [['log', 'extra'], 'It has a class for list "extra", which isn’t declared.'],
]);
