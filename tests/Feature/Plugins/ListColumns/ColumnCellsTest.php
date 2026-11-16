<?php

declare(strict_types=1);

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Columns\ColumnAnswerRefused;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\Columns\ColumnTooSlow;
use App\Domain\Plugins\Columns\PluginColumns;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Lists\ListColumn;
use Teme\Contracts\Lists\ListRow;
use Teme\Contracts\Lists\ListRows;
use Teme\Contracts\Plugins\Plugin;
use Tests\Fixtures\Plugins\ListColumnCalls;
use Tests\Fixtures\Plugins\PluginFixtures;
use Throwable;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    $this->admin = User::factory()->create()->assignRole('administrator');
    ListColumnCalls::reset();

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

        /** @param  list<int>  $steps */
        public function step(array $steps): void
        {
            $this->steps = $steps;
            $this->now = 0;
        }
    };
    app()->instance(ColumnClock::class, $this->clock);

    $this->enable = function (string ...$folders): void {
        PluginFixtures::use(...$folders);
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        foreach ($folders as $folder) {
            $name = str_starts_with($folder, '/')
                ? (json_decode((string) file_get_contents($folder.'/teme-plugin.json'), true)['name'] ?? 'tests/columns')
                : 'tests/'.$folder;
            $manifest = app(PluginRegistry::class)->all()[$name]->manifest;
            app(EnablePlugin::class)->execute(new EnablePluginInput(
                $name,
                $manifest->version(),
                $manifest->capabilities(),
                $manifest->hosts(),
                $manifest->hooks(),
                null,
            ));
        }
        app(PluginLoader::class)->load();
    };

    $this->rows = fn (): array => [
        new ListRow('a', 'published', null, '2026-11-16T00:00:00Z'),
        new ListRow('b', 'draft', 'en', '2026-11-16T01:00:00Z'),
        new ListRow('c', 'published', null, '2026-11-16T02:00:00Z'),
    ];

    $this->mode = function (string $mode): void {
        app(UpdateSettings::class)->execute(new UpdateSettingsInput(
            'plugin.columns',
            ['mode' => $mode],
            [],
            [],
            0,
            (string) $this->admin->id,
        ));
    };
});

afterEach(fn () => PluginFixtures::forget());

it('asks a shown column once with every row of the page, in order, and gives its cells by row id', function (): void {
    ($this->enable)('columns');
    ListColumnCalls::reset();

    $result = app(PluginColumns::class)->cells('post', ['plugin:columns:score'], ($this->rows)());

    expect(ListColumnCalls::$seen)->toHaveCount(1)
        ->and(ListColumnCalls::$seen[0])->toBeInstanceOf(ListRows::class)
        ->and(ListColumnCalls::$seen[0]->type)->toBe('post')
        ->and(ListColumnCalls::$seen[0]->ids())->toBe(['a', 'b', 'c'])
        ->and(ListColumnCalls::$seen[0]->rows[0]->status)->toBe('published')
        ->and(ListColumnCalls::$seen[0]->rows[1]->language)->toBe('en')
        ->and(ListColumnCalls::$seen[0]->rows[0]->updatedAt)->toBe('2026-11-16T00:00:00Z')
        ->and($result->values['plugin:columns:score']['a'])->toBe(['kind' => 'badge', 'value' => 'Good', 'tone' => 'ok'])
        ->and(array_keys($result->values['plugin:columns:score']))->toBe(['a', 'b', 'c']);
});

it('never asks a column the person doesn\'t show, or any column for an empty page', function (): void {
    ($this->enable)('columns');
    ListColumnCalls::reset();

    app(PluginColumns::class)->cells('post', ['author', 'date'], ($this->rows)());
    expect(ListColumnCalls::$seen)->toBe([]);

    ListColumnCalls::reset();
    app(PluginColumns::class)->cells('post', ['plugin:columns:score'], []);
    expect(ListColumnCalls::$seen)->toBe([]);
});

it('asks columns in the person\'s order', function (): void {
    ($this->enable)('columns');
    ListColumnCalls::reset();

    app(PluginColumns::class)->cells('post', ['plugin:columns:note', 'plugin:columns:score'], ($this->rows)());

    expect(ListColumnCalls::$seen)->toHaveCount(2);
});

it('leaves a throwing column empty, records the fault under its plugin, and fills the others', function (): void {
    ($this->enable)('columns');
    ($this->mode)('throw');
    ListColumnCalls::reset();

    $result = app(PluginColumns::class)->cells('post', ['plugin:columns:note', 'plugin:columns:score'], ($this->rows)());

    expect($result->values)->not->toHaveKey('plugin:columns:note')
        ->and($result->values)->toHaveKey('plugin:columns:score')
        ->and($result->problems)->toBe(['Columns'])
        ->and($this->faults->seen)->toHaveCount(1)
        ->and($this->faults->seen[0][0])->toBe('tests/columns')
        ->and($this->faults->seen[0][1])->toBe('tests/columns#column:note')
        ->and($this->faults->seen[0][2])->toBe(ListColumn::class);
});

it('drops a column\'s cells when its call takes more than 100 ms, and records ColumnTooSlow', function (): void {
    ($this->enable)('columns');
    $this->clock->step([0, 101_000_000]);

    $result = app(PluginColumns::class)->cells('post', ['plugin:columns:score'], ($this->rows)());

    expect($result->values)->not->toHaveKey('plugin:columns:score')
        ->and($result->problems)->toBe(['Columns'])
        ->and($this->faults->seen[0][3])->toBeInstanceOf(ColumnTooSlow::class);
});

it('stops asking once 300 ms are spent on the page', function (): void {
    // B-01 (draft): plan said 99 ms × 3, but 297 < 300 so the fourth would still
    // be asked; 100 ms × 3 reaches the page budget while staying within the call budget.
    $second = PluginFixtures::copy('columns', [
        'name' => 'tests/columns-b',
        'code' => 'columns-b',
        'label' => 'Columns B',
        'plugin' => 'Tests\\Fixtures\\Plugins\\Columns\\OneScore',
        'contributes' => [
            'settings' => [
                ['key' => 'mode', 'type' => 'text', 'label' => 'Mode', 'description' => 'Mode.'],
            ],
            'columns' => [
                ['id' => 'score', 'label' => 'SEO score', 'kind' => 'badge'],
            ],
        ],
    ], ['name' => 'tests/columns-b'], [
        'src/OneScore.php' => <<<'PHP'
<?php
namespace Tests\Fixtures\Plugins\Columns;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Plugins\Plugin;
final class OneScore implements HasListColumns, Plugin {
    public function register(Hooks $hooks): void {}
    public function listColumns(): array { return ['score' => new ScoreColumn('answer')]; }
}
PHP,
    ]);

    ($this->enable)('columns', $second);
    $this->clock->step([
        0, 100_000_000,
        0, 100_000_000,
        0, 100_000_000,
        0, 100_000_000,
    ]);
    ListColumnCalls::reset();

    $result = app(PluginColumns::class)->cells('post', [
        'plugin:columns:score',
        'plugin:columns:views',
        'plugin:columns:note',
        'plugin:columns-b:score',
    ], ($this->rows)());

    expect(ListColumnCalls::$seen)->toHaveCount(3)
        ->and($result->values)->not->toHaveKey('plugin:columns-b:score')
        ->and($result->problems)->toContain('Columns B')
        ->and(collect($this->faults->seen)->contains(fn (array $f): bool => str_contains($f[1], 'columns-b')))->toBeFalse();
});

it('keeps only cells of this page\'s rows and of the column\'s kind, and records one ColumnAnswerRefused', function (): void {
    ($this->enable)('columns');
    ($this->mode)('wrong');

    $result = app(PluginColumns::class)->cells('post', ['plugin:columns:score'], ($this->rows)());

    expect($result->values['plugin:columns:score'] ?? null)->toBe([
        'c' => ['kind' => 'badge', 'value' => 'Kept', 'tone' => 'ok'],
    ])->and($this->faults->seen)->toHaveCount(1)
        ->and($this->faults->seen[0][3])->toBeInstanceOf(ColumnAnswerRefused::class);
});

it('cleans text before it is shown', function (string $input, ?string $expected, string $kind): void {
    ($this->enable)('columns');

    $id = $kind === 'badge' ? 'score' : 'note';
    $key = 'plugin:columns:'.$id;

    $plugin = new class($input, $kind, $id) implements HasListColumns, Plugin
    {
        public function __construct(private string $input, private string $kind, private string $id) {}

        public function register(Hooks $hooks): void {}

        public function listColumns(): array
        {
            $input = $this->input;
            $kind = $this->kind;

            return [
                $this->id => new class($input, $kind) implements ListColumn
                {
                    public function __construct(private string $input, private string $kind) {}

                    public function cells(ListRows $rows): array
                    {
                        return [
                            $rows->rows[0]->id => $this->kind === 'badge'
                                ? Cell::badge($this->input)
                                : Cell::text($this->input),
                        ];
                    }
                },
            ];
        }
    };

    app(LoadedPlugins::class)->started('tests/columns', $plugin);

    $result = app(PluginColumns::class)->cells('post', [$key], [
        new ListRow('a', 'published', null, '2026-11-16T00:00:00Z'),
    ]);

    if ($expected === null) {
        expect($result->values[$key] ?? [])->toBe([]);
    } else {
        expect($result->values[$key]['a']['value'])->toBe($expected);
    }
})->with([
    'newline' => ["two\nlines", 'two lines', 'text'],
    'bidi' => ["a\u{202E}b", 'a b', 'text'],
    'long text' => [str_repeat('x', 100), str_repeat('x', 79).'…', 'text'],
    'long badge' => [str_repeat('y', 30), str_repeat('y', 23).'…', 'badge'],
    'blank' => ['  ', null, 'text'],
    'markup kept' => ['<img src=x onerror=alert(1)>', '<img src=x onerror=alert(1)>', 'text'],
]);
