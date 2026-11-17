<?php

declare(strict_types=1);

use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\Area\AreaTooSlow;
use App\Domain\Plugins\Columns\ColumnClock;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\SavePluginRecordInput;
use App\Domain\Plugins\Operations\SavePluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\NoLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Content\WriteRefused;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();
    $this->editor = User::factory()->create()->assignRole('editor');

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

    // The page cache's store, telling what was purged.
    config(['page_cache.enabled' => true]);
    $this->purged = new ArrayObject;
    $purged = $this->purged;
    $this->app->bind(PageStore::class, fn (): PageStore => new class($purged) implements PageStore
    {
        public function __construct(private ArrayObject $purged) {}

        public function get(PageKey $key): ?PageEntry
        {
            return null;
        }

        public function put(PageEntry $entry): void {}

        public function stale(PageKey $key): ?PageEntry
        {
            return null;
        }

        public function markStale(array $tags, int $until): int
        {
            foreach ($tags as $tag) {
                $this->purged->append($tag);
            }

            return 0;
        }

        public function purge(array $tags): int
        {
            foreach ($tags as $tag) {
                $this->purged->append($tag);
            }

            return 0;
        }

        public function forget(string $key): void {}

        public function flush(): int
        {
            return 0;
        }

        public function prune(int $now): int
        {
            return 0;
        }

        public function lock(PageKey $key, int $seconds): Lock
        {
            return new NoLock($key->lockName(), $seconds);
        }

        public function keys(array $tags, int $limit): array
        {
            return [];
        }
    });

    PluginFixtures::use('placard');
    $manifest = app(PluginRegistry::class)->find('tests/placard')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest?->version() ?? '1.0.0', $manifest?->capabilities() ?? [], $manifest?->hosts() ?? [], $manifest?->hooks() ?? [], null));
    app(PluginLoader::class)->load();

    $this->ad = [
        'name' => 'Sponsor banner',
        'location' => 'after_paragraph',
        'paragraph' => '3',
        'versions' => [['label' => 'Wide', 'code' => '<div class="ad">Ad</div>', 'weight' => '2']],
        'types' => ['post'],
        'terms' => [],
        'region' => 'footer',
    ];

    $this->save = fn (array $values, ?string $id = null, ?User $person = null, string $record = 'ad'): array => app(SavePluginRecord::class)->execute(new SavePluginRecordInput('placard', $record, $id, $values, (string) ($person ?? $this->editor)->id));
    $this->purged->exchangeArray([]);
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('saves a record for a holder of the area’s permission, the plugin handed only the fields that show, each as its type', function (): void {
    $saved = ($this->save)($this->ad);

    $values = PlacardArea::$calls[0][1][2];

    expect($saved['changed'])->toBeTrue()
        ->and(PlacardArea::$calls[0][0])->toBe('save')
        ->and(PlacardArea::$calls[0][1][1])->toBeNull()
        ->and($values)->toBe([
            'name' => 'Sponsor banner',
            'location' => 'after_paragraph',
            'paragraph' => 3,
            'versions' => [['label' => 'Wide', 'code' => '<div class="ad">Ad</div>', 'weight' => 2]],
            'types' => ['post'],
            'terms' => [],
        ])
        ->and(DB::table('placard_ads')->where('id', (int) $saved['id'])->value('name'))->toBe('Sponsor banner')
        ->and(PlacardArea::$calls[0][1][3]->personId)->toBe((string) $this->editor->id);
});

it('purges the area’s declared tags after the commit', function (): void {
    ($this->save)($this->ad);

    expect(array_values(array_unique($this->purged->getArrayCopy())))->toEqualCanonicalizing(['insertions', 'page-insertions']);
});

it('shows the plugin’s refusal at the top of the form, and keeps none of what it wrote', function (): void {
    PlacardArea::$on['save'] = function (): Saved {
        DB::table('placard_ads')->insert(['name' => 'Half saved', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        throw new WriteRefused('An ad with this name already runs.');
    };

    try {
        ($this->save)($this->ad);
        $this->fail('The save was not refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe(['record' => ['An ad with this name already runs.']]);
    }

    expect(DB::table('placard_ads')->count())->toBe(0)
        ->and($this->purged->getArrayCopy())->toBe([]);
});

it('rolls back a save that runs over its budget or throws, records the fault and says nothing was changed', function (Closure $save, string $fault): void {
    PlacardArea::$on['save'] = $save->bindTo($this);

    try {
        ($this->save)($this->ad);
        $this->fail('The save was not refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe(['record' => ['Placard couldn’t save this. Nothing was changed.']]);
    }

    expect(DB::table('placard_ads')->count())->toBe(0)
        ->and($this->purged->getArrayCopy())->toBe([])
        ->and($this->faults->seen[0][1])->toBe('tests/placard#area')
        ->and($this->faults->seen[0][3])->toBeInstanceOf($fault);
})->with([
    'slow' => [function (): Saved {
        DB::table('placard_ads')->insert(['name' => 'Slow', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->clock->steps = [2_500_000_000];

        return new Saved('1');
    }, AreaTooSlow::class],
    'throwing' => [function (): Saved {
        DB::table('placard_ads')->insert(['name' => 'Broken', 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        throw new RuntimeException('The disk is full.');
    }, RuntimeException::class],
]);

it('refuses values the form’s rules refuse, under each field’s key, writing nothing', function (array $change, string $key): void {
    try {
        ($this->save)([...$this->ad, ...$change]);
        $this->fail('The save was not refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toHaveKey($key);
    }

    expect(PlacardArea::$calls)->toBe([])
        ->and(DB::table('placard_ads')->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'values.name'],
    'a name too long' => [['name' => str_repeat('a', 121)], 'values.name'],
    'an unknown option' => [['location' => 'sidebar'], 'values.location'],
    'a paragraph out of bounds' => [['paragraph' => '99'], 'values.paragraph'],
    'no versions' => [['versions' => []], 'values.versions'],
    'a version’s weight out of bounds' => [['versions' => [['label' => 'Wide', 'code' => '', 'weight' => '0']]], 'values.versions.0.weight'],
    'an unknown content type' => [['types' => ['nothing']], 'values.types'],
    'an unknown term' => [['terms' => ['999999']], 'values.terms'],
]);

it('neither checks nor hands over a field its condition hides', function (): void {
    // Paragraph shows only after a paragraph, and the region only in a region: both hidden, both out of bounds.
    ($this->save)([...$this->ad, 'location' => 'between_items', 'paragraph' => '999', 'region' => 'nowhere-at-all']);

    expect(PlacardArea::$calls[0][1][2])->not->toHaveKey('paragraph')
        ->and(PlacardArea::$calls[0][1][2])->not->toHaveKey('region');
});

it('refuses, writing nothing, a person without the permission, a disabled plugin, or an undeclared record', function (Closure $refuse): void {
    $refuse->call($this);

    expect(PlacardArea::$calls)->toBe([])
        ->and(DB::table('placard_ads')->count())->toBe(0);
})->with([
    'no permission' => [function (): void {
        expect(fn () => ($this->save)($this->ad, person: User::factory()->create()->assignRole('author')))->toThrow(AuthorizationException::class);
    }],
    'a disabled plugin' => [function (): void {
        app(DisablePlugin::class)->execute(new DisablePluginInput('tests/placard', null));
        expect(fn () => ($this->save)($this->ad))->toThrow(AuthorizationException::class);
    }],
    'an undeclared record' => [function (): void {
        expect(fn () => ($this->save)($this->ad, record: 'banner'))->toThrow(AuthorizationException::class);
    }],
]);
