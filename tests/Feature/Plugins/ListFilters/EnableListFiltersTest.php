<?php

declare(strict_types=1);

use App\Domain\Plugins\ListFilters\PluginListFilters;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

/** The options the fixture declares, as the manifest reads them. */
const LIST_FILTER_OPTIONS = [['value' => 'ready', 'label' => 'Ready'], ['value' => 'stale', 'label' => 'Stale']];

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);

    $this->enable = function (string $name): string {
        $plugin = app(PluginRegistry::class)->find($name);
        $manifest = $plugin?->manifest;

        return app(EnablePlugin::class)->execute(new EnablePluginInput(
            $name,
            $manifest?->version() ?? '1.0.0',
            $manifest?->capabilities() ?? [],
            $manifest?->hosts() ?? [],
            $manifest?->hooks() ?? [],
            null,
        ));
    };

    $this->filter = fn (string $id, array $changes = []): array => array_replace([
        'id' => $id,
        'label' => 'Review state',
        'types' => ['post'],
        'options' => LIST_FILTER_OPTIONS,
        'table' => 'listfilters_scores',
        'column' => 'state',
        'item' => 'content_id',
    ], $changes);

    // The index migration a plugin ships when its filter's column is indexed.
    $this->indexed = [
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
    ];
});

afterEach(fn () => PluginFixtures::forget());

it('refuses to enable a plugin whose filter column leads no index, and writes nothing', function (): void {
    PluginFixtures::use('listfilters');

    expect(fn () => ($this->enable)('tests/listfilters'))
        ->toThrow(ValidationException::class, 'The list filter “Review state” reads listfilters_scores.state, which no valid index leads with, so the plugin can’t be enabled.')
        ->and(Schema::hasTable('listfilters_scores'))->toBeFalse()
        ->and(PluginRecord::query()->where('name', 'tests/listfilters')->where('state', PluginRecord::ENABLED)->exists())->toBeFalse();
});

it('enables a plugin whose filter column leads a valid index, and offers the filter for its type', function (): void {
    $folder = PluginFixtures::copy('listfilters', [], files: $this->indexed);
    PluginFixtures::use($folder);

    ($this->enable)('tests/listfilters');

    expect(PluginRecord::query()->where('name', 'tests/listfilters')->where('state', PluginRecord::ENABLED)->exists())->toBeTrue()
        ->and(array_map(fn ($filter): string => $filter->key, app(PluginListFilters::class)->forType('post')))
        ->toBe(['plugin:listfilters:review-state'])
        ->and(app(PluginListFilters::class)->forType('page'))->toBe([]);
});

it('refuses a filter whose column the table does not have', function (): void {
    $folder = PluginFixtures::copy('listfilters', [
        'contributes' => ['listFilters' => [($this->filter)('review-state', ['column' => 'missing'])]],
    ], files: $this->indexed);
    PluginFixtures::use($folder);

    expect(fn () => ($this->enable)('tests/listfilters'))
        ->toThrow(ValidationException::class, 'The list filter “Review state” reads listfilters_scores.missing, which the table doesn’t have.');
});

it('refuses a third filter on one list, and writes nothing', function (): void {
    $folder = PluginFixtures::copy('listfilters', [
        'contributes' => ['listFilters' => [($this->filter)('review-state'), ($this->filter)('second')]],
    ], files: $this->indexed);
    PluginFixtures::use($folder);
    ($this->enable)('tests/listfilters');

    $other = PluginFixtures::copy('listfilters', [
        'name' => 'tests/listfilters-two',
        'code' => 'listfilters-two',
        'owns' => ['tables' => ['listfilters_two_scores']],
        'contributes' => ['listFilters' => [
            ($this->filter)('one', ['table' => 'listfilters_two_scores']),
            ($this->filter)('two', ['table' => 'listfilters_two_scores']),
        ]],
    ], ['name' => 'tests/listfilters-two']);
    PluginFixtures::use($folder, $other);

    expect(fn () => ($this->enable)('tests/listfilters-two'))
        ->toThrow(ValidationException::class, 'A post list shows at most 3 filters, and enabling this plugin would make 4.')
        ->and(PluginRecord::query()->where('name', 'tests/listfilters-two')->where('state', PluginRecord::ENABLED)->exists())->toBeFalse();
});

it('drops a disabled plugin’s filter from the list', function (): void {
    $folder = PluginFixtures::copy('listfilters', [], files: $this->indexed);
    PluginFixtures::use($folder);
    ($this->enable)('tests/listfilters');

    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/listfilters', null));

    expect(app(PluginListFilters::class)->forType('post'))->toBe([]);
});
