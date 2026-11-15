<?php

declare(strict_types=1);

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);

    $this->enable = function (string $name = 'tests/tables'): string {
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
});

afterEach(fn () => PluginFixtures::forget());

it('leaves a plugin’s tables alone when artisan migrate runs', function (): void {
    PluginFixtures::use('tables');
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/tables',
        'code' => 'tables',
        'version' => PluginFixtures::manifest('tables')['version'],
        'consent' => [
            'capabilities' => PluginFixtures::manifest('tables')['capabilities'] ?? [],
            'hosts' => PluginFixtures::manifest('tables')['hosts'] ?? [],
            'hooks' => PluginFixtures::manifest('tables')['hooks'] ?? [],
        ],
    ]);

    Artisan::call('migrate');

    expect(Schema::hasTable('tables_links'))->toBeFalse()
        ->and(Schema::hasTable('tables_clicks'))->toBeFalse();
});

it('enabling the fixture creates both tables and records both migrations', function (): void {
    PluginFixtures::use('tables');

    ($this->enable)();

    $record = PluginRecord::query()->where('name', 'tests/tables')->sole();

    expect(Schema::hasTable('tables_links'))->toBeTrue()
        ->and(Schema::hasTable('tables_clicks'))->toBeTrue()
        ->and($record->state)->toBe('enabled')
        ->and(array_keys($record->migrations))->toBe([
            '2026_01_01_000000_create_tables_links',
            '2026_01_02_000000_create_tables_clicks',
        ])
        ->and($record->migrations['2026_01_01_000000_create_tables_links']['version'])->toBe('1.0.0')
        ->and($record->migrations['2026_01_01_000000_create_tables_links']['tables'])->toBe(['tables_links'])
        ->and($record->migrations['2026_01_02_000000_create_tables_clicks']['tables'])->toBe(['tables_clicks'])
        ->and($record->migrations['2026_01_01_000000_create_tables_links']['ran_at'])->not->toBeEmpty();
});

it('refuses a migration that creates a table without the plugin’s prefix, and leaves nothing behind', function (): void {
    $copy = PluginFixtures::copy('tables', files: [
        'database/migrations/2026_01_02_000000_create_tables_clicks.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP,
    ]);
    PluginFixtures::use($copy);

    expect(fn () => ($this->enable)())
        ->toThrow(ValidationException::class, 'The migration 2026_01_02_000000_create_tables_clicks of Tables created the table links, which doesn’t start with its prefix "tables_".');

    expect(Schema::hasTable('tables_links'))->toBeFalse()
        ->and(Schema::hasTable('links'))->toBeFalse()
        ->and(PluginRecord::query()->count())->toBe(0);
});

it('refuses a migration that creates a table not listed under owns.tables, and leaves nothing behind', function (): void {
    $copy = PluginFixtures::copy('tables', files: [
        'database/migrations/2026_01_02_000000_create_tables_clicks.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_other', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP,
    ]);
    PluginFixtures::use($copy);

    expect(fn () => ($this->enable)())
        ->toThrow(ValidationException::class, 'The migration 2026_01_02_000000_create_tables_clicks of Tables created the table tables_other, which isn’t one it lists under owns.tables.');

    expect(Schema::hasTable('tables_links'))->toBeFalse()
        ->and(Schema::hasTable('tables_other'))->toBeFalse()
        ->and(PluginRecord::query()->count())->toBe(0);
});

it('refuses a migration that drops a table that isn’t the plugin’s, and leaves nothing behind', function (): void {
    // users (and most core tables) can't be dropped while foreign keys point at them;
    // a disposable table that isn't the plugin's proves the same check.
    Schema::create('core_scratch', function (Blueprint $table): void {
        $table->id();
    });

    $copy = PluginFixtures::copy('tables', files: [
        'database/migrations/2026_01_02_000000_create_tables_clicks.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::drop('core_scratch');
    }
};
PHP,
    ]);
    PluginFixtures::use($copy);

    expect(fn () => ($this->enable)())
        ->toThrow(ValidationException::class, 'The migration 2026_01_02_000000_create_tables_clicks of Tables dropped the table core_scratch, which isn’t one of its tables.');

    expect(Schema::hasTable('core_scratch'))->toBeTrue()
        ->and(Schema::hasTable('tables_links'))->toBeFalse()
        ->and(PluginRecord::query()->count())->toBe(0);

    Schema::dropIfExists('core_scratch');
});

it('refuses a migration that sets withinTransaction to false', function (): void {
    $copy = PluginFixtures::copy('tables', files: [
        'database/migrations/2026_01_01_000000_create_tables_links.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('tables_links', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP,
    ]);
    PluginFixtures::use($copy);

    expect(fn () => ($this->enable)())
        ->toThrow(ValidationException::class, 'The migration 2026_01_01_000000_create_tables_links of Tables can’t run outside a transaction.');

    expect(Schema::hasTable('tables_links'))->toBeFalse()
        ->and(PluginRecord::query()->count())->toBe(0);
});

it('refuses creating a table that belongs to another installed plugin’s longer prefix', function (): void {
    $other = PluginFixtures::copy('tables', [
        'name' => 'tests/tables-x',
        'code' => 'tables-x',
        'label' => 'Tables X',
        'owns' => ['tables' => ['tables_x_links']],
        'plugin' => 'Tests\\Fixtures\\Plugins\\Tables\\Tables',
    ], ['name' => 'tests/tables-x'], [
        'database/migrations/2026_01_01_000000_create_tables_links.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_x_links', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP,
        'database/migrations/2026_01_02_000000_create_tables_clicks.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void {}
};
PHP,
    ]);

    $bad = PluginFixtures::copy('tables', [
        'owns' => ['tables' => ['tables_links', 'tables_clicks', 'tables_x_taken']],
    ], files: [
        'database/migrations/2026_01_03_000000_create_tables_x_taken.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_x_taken', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP,
    ]);

    PluginFixtures::use($other, $bad);
    ($this->enable)('tests/tables-x');

    expect(fn () => ($this->enable)('tests/tables'))
        ->toThrow(ValidationException::class, 'The migration 2026_01_03_000000_create_tables_x_taken of Tables created the table tables_x_taken, which belongs to another plugin.');

    expect(Schema::hasTable('tables_x_taken'))->toBeFalse()
        ->and(Schema::hasTable('tables_links'))->toBeFalse()
        ->and(PluginRecord::query()->where('name', 'tests/tables')->exists())->toBeFalse()
        ->and(Schema::hasTable('tables_x_links'))->toBeTrue();
});

it('enabling again with nothing waiting runs no further migrations', function (): void {
    PluginFixtures::use('tables');
    ($this->enable)();

    $before = PluginRecord::query()->sole()->migrations;
    ($this->enable)();

    expect(PluginRecord::query()->sole()->migrations)->toBe($before)
        ->and(Schema::hasTable('tables_links'))->toBeTrue();
});
