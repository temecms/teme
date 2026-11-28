<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\MigratePluginInput;
use App\Domain\Plugins\Operations\MigratePlugin;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);

    $this->enable = function (string $name): string {
        $manifest = app(PluginRegistry::class)->find($name)?->manifest;

        return app(EnablePlugin::class)->execute(new EnablePluginInput(
            $name,
            $manifest?->version() ?? '1.0.0',
            $manifest?->capabilities() ?? [],
            $manifest?->hosts() ?? [],
            $manifest?->hooks() ?? [],
            null,
        ));
    };

    $this->filter = fn (array $changes = []): array => array_replace([
        'id' => 'review-state',
        'label' => 'Review state',
        'types' => ['post'],
        'options' => [['value' => 'ready', 'label' => 'Ready'], ['value' => 'stale', 'label' => 'Stale']],
        'table' => 'listfilters_scores',
        'column' => 'state',
        'item' => 'content_id',
    ], $changes);

    // The index the base fixture's filter needs, as one migration file.
    // The table a filter may read a non-text column of.
    $this->ranks = ['database/migrations/2026_01_04_000000_create_listfilters_ranks.php' => ranksMigration()];

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

it('refuses a filter that reads a table the plugin does not list under owns.tables', function (string $table): void {
    $folder = PluginFixtures::copy('listfilters', [
        'contributes' => ['listFilters' => [($this->filter)(['table' => $table])]],
    ], files: $this->indexed);
    PluginFixtures::use($folder);

    expect(fn () => ($this->enable)('tests/listfilters'))
        ->toThrow(ValidationException::class, 'owns.tables')
        ->and(PluginRecord::query()->where('name', 'tests/listfilters')->where('state', PluginRecord::ENABLED)->exists())->toBeFalse();
})->with(['the content table' => ['contents'], 'the users table' => ['users'], 'another plugin’s table' => ['listfilters_two_scores']]);

it('refuses a filter that reads a table its own migrations do not create', function (): void {
    $folder = PluginFixtures::copy('listfilters', [
        'owns' => ['tables' => ['listfilters_scores', 'listfilters_ghosts']],
        'contributes' => ['listFilters' => [($this->filter)(['table' => 'listfilters_ghosts'])]],
    ], files: $this->indexed);
    PluginFixtures::use($folder);

    expect(fn () => ($this->enable)('tests/listfilters'))
        ->toThrow(ValidationException::class, 'reads listfilters_ghosts, which the plugin’s own migrations don’t create');
});

it('refuses a filter whose column is not text', function (): void {
    $folder = PluginFixtures::copy('listfilters', [
        'owns' => ['tables' => ['listfilters_scores', 'listfilters_ranks']],
        'contributes' => ['listFilters' => [($this->filter)(['table' => 'listfilters_ranks', 'column' => 'rank'])]],
    ], files: [...$this->indexed, ...$this->ranks]);
    PluginFixtures::use($folder);

    expect(fn () => ($this->enable)('tests/listfilters'))
        ->toThrow(ValidationException::class, 'reads listfilters_ranks.rank, which isn’t text');
});

it('refuses a filter whose item column is not text', function (): void {
    $folder = PluginFixtures::copy('listfilters', [
        'owns' => ['tables' => ['listfilters_scores', 'listfilters_ranks']],
        'contributes' => ['listFilters' => [($this->filter)(['table' => 'listfilters_ranks', 'column' => 'state', 'item' => 'ref'])]],
    ], files: [...$this->indexed, ...$this->ranks]);
    PluginFixtures::use($folder);

    expect(fn () => ($this->enable)('tests/listfilters'))
        ->toThrow(ValidationException::class, 'keys its rows by listfilters_ranks.ref, which isn’t text');
});

it('refuses an index that is not a plain btree or hash index on the column', function (): void {
    $folder = PluginFixtures::copy('listfilters', [], files: [
        'database/migrations/2026_01_02_000000_brin_listfilters_scores.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX listfilters_scores_state_index ON listfilters_scores USING brin (state)');
    }
};
PHP,
    ]);
    PluginFixtures::use($folder);

    expect(fn () => ($this->enable)('tests/listfilters'))
        ->toThrow(ValidationException::class, 'no valid index leads with');
});

it('refuses an update that adds a filter on an unindexed column, and undoes its migrations', function (): void {
    PluginFixtures::use(PluginFixtures::copy('listfilters', [], files: $this->indexed));
    ($this->enable)('tests/listfilters');

    $update = PluginFixtures::copy('listfilters', [
        'owns' => ['tables' => ['listfilters_scores', 'listfilters_notes']],
        'contributes' => ['listFilters' => [
            ($this->filter)(),
            ($this->filter)(['id' => 'note-state', 'label' => 'Note state', 'table' => 'listfilters_notes']),
        ]],
    ], files: [...$this->indexed, 'database/migrations/2026_01_03_000000_create_listfilters_notes.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listfilters_notes', function (Blueprint $table): void {
            $table->id();
            $table->char('content_id', 26);
            $table->string('state', 20);
        });
    }
};
PHP]);
    PluginFixtures::use($update);

    expect(fn () => app(MigratePlugin::class)->execute(new MigratePluginInput('tests/listfilters', null)))
        ->toThrow(ValidationException::class, 'reads listfilters_notes.state, which no valid index leads with')
        ->and(Schema::hasTable('listfilters_notes'))->toBeFalse()
        ->and(array_keys(PluginRecord::query()->where('name', 'tests/listfilters')->sole()->migrations))->not->toContain('2026_01_03_000000_create_listfilters_notes');
});

it('refuses an update whose migration drops the index a filter relies on, and keeps the index', function (): void {
    PluginFixtures::use(PluginFixtures::copy('listfilters', [], files: $this->indexed));
    ($this->enable)('tests/listfilters');

    $update = PluginFixtures::copy('listfilters', [], files: [...$this->indexed, 'database/migrations/2026_01_03_000000_drop_listfilters_index.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX listfilters_scores_state_index');
    }
};
PHP]);
    PluginFixtures::use($update);

    expect(fn () => app(MigratePlugin::class)->execute(new MigratePluginInput('tests/listfilters', null)))
        ->toThrow(ValidationException::class, 'no valid index leads with')
        ->and(DB::select("select 1 from pg_indexes where indexname = 'listfilters_scores_state_index'"))->not->toBeEmpty();
});

/** A migration that makes a table with a text column, an integer column and an integer item column, each indexed where a filter could read it. */
function ranksMigration(): string
{
    return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listfilters_ranks', function (Blueprint $table): void {
            $table->id();
            $table->char('content_id', 26);
            $table->string('state', 20)->index();
            $table->integer('rank')->index();
            $table->integer('ref');
        });
    }
};
PHP;
}
