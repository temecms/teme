<?php

declare(strict_types=1);

use App\Domain\Hooks\ListenerFaults;
use App\Domain\Plugins\ListFilters\ListFilterUnavailable;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->editor = User::factory()->create()->assignRole('editor');

    $this->ready = lifecycleDraft($this->editor, 'Harbour news');
    $this->stale = lifecycleDraft($this->editor, 'Ferry fares');

    // The plugin, its table, and the index a filter's column needs before it can be enabled.
    $folder = PluginFixtures::copy('listfilters', [], files: [
        'database/migrations/2026_01_02_000000_index_listfilters_scores.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listfilters_scores', function (Blueprint $table): void {
            $table->index('state');
        });
    }
};
PHP,
    ]);
    PluginFixtures::use($folder);
    $this->folder = $folder;

    $manifest = app(PluginRegistry::class)->find('tests/listfilters')->manifest;
    // The table is made by the plugin's own migrations, so its rows are written after enabling.
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/listfilters', $manifest->version(), [], [], [], null));
    DB::table('listfilters_scores')->insert([
        ['content_id' => $this->ready->id, 'state' => 'ready'],
        ['content_id' => $this->stale->id, 'state' => 'stale'],
    ]);

    $this->page = fn (array $query = []): array => $this->actingAs($this->editor)
        ->get(route('content.index', ['type' => 'posts', ...$query]))
        ->assertOk()
        ->inertiaProps();
    $this->titles = fn (array $page): array => array_column($page['rows'], 'title');
});

afterEach(fn () => PluginFixtures::forget());

it('narrows the list to the items the plugin’s column matches, exactly', function (): void {
    $page = ($this->page)(['plugin:listfilters:review-state' => 'ready']);

    expect(($this->titles)($page))->toBe(['Harbour news'])
        ->and($page['filtered'])->toBeTrue();
});

it('ignores a value that is not one of the declared options', function (): void {
    $page = ($this->page)(['plugin:listfilters:review-state' => 'bogus']);

    expect(($this->titles)($page))->toEqualCanonicalizing(['Harbour news', 'Ferry fares'])
        ->and($page['filters'])->not->toHaveKey('plugin:listfilters:review-state');
});

it('offers the select with the declared options only', function (): void {
    $page = ($this->page)();

    expect($page['listFilters'])->toBe([[
        'key' => 'plugin:listfilters:review-state',
        'label' => 'Review state',
        'options' => [['value' => 'ready', 'label' => 'Ready'], ['value' => 'stale', 'label' => 'Stale']],
    ]]);
});

it('keeps offering the filters checked at enable when the manifest on disk changes them, until its changes are run', function (): void {
    $path = $this->folder.'/teme-plugin.json';
    $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $manifest['contributes']['listFilters'][0]['label'] = 'Changed without a check';
    $manifest['contributes']['listFilters'][0]['options'][] = ['value' => 'unchecked', 'label' => 'Unchecked'];
    file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    app(PluginRegistry::class)->flush();

    $page = ($this->page)(['plugin:listfilters:review-state' => 'unchecked']);

    expect($page['listFilters'])->toBe([[
        'key' => 'plugin:listfilters:review-state',
        'label' => 'Review state',
        'options' => [['value' => 'ready', 'label' => 'Ready'], ['value' => 'stale', 'label' => 'Stale']],
    ]])
        ->and(($this->titles)($page))->toEqualCanonicalizing(['Harbour news', 'Ferry fares']);
});

it('reads the plugin’s table through its index, as the list’s query does', function (): void {
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        if (str_contains($query->sql, 'listfilters_scores')) {
            $sql = [$query->sql, $query->bindings];
        }
    });

    ($this->page)(['plugin:listfilters:review-state' => 'ready']);

    expect($sql)->not->toBe([]);

    // The subquery's plan, with sequential scans off, uses the index the filter's column leads.
    $plan = DB::transaction(function () use ($sql): string {
        DB::statement('SET LOCAL enable_seqscan = off');

        return implode("\n", array_map(fn (object $line): string => (string) array_values((array) $line)[0], DB::select('EXPLAIN '.$sql[0], $sql[1])));
    });

    expect($plan)->toContain('listfilters_scores_state_index');
});

it('drops a filter whose table is gone, records a fault under its plugin, and still loads the list', function (): void {
    $faults = new class implements ListenerFaults
    {
        /** @var list<array{owner: string, id: string, hook: string, failure: Throwable}> */
        public array $recorded = [];

        public function record(string $owner, string $id, string $hook, Throwable $failure): void
        {
            $this->recorded[] = ['owner' => $owner, 'id' => $id, 'hook' => $hook, 'failure' => $failure];
        }
    };
    app()->instance(ListenerFaults::class, $faults);
    DB::statement('DROP TABLE listfilters_scores');

    $page = ($this->page)(['plugin:listfilters:review-state' => 'ready']);

    expect($page['listFilters'])->toBe([])
        ->and($page['filters'])->not->toHaveKey('plugin:listfilters:review-state')
        ->and(array_column($faults->recorded, 'owner'))->toBe(['tests/listfilters'])
        ->and($faults->recorded[0]['failure'])->toBeInstanceOf(ListFilterUnavailable::class);
});
