<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\MigratePluginInput;
use App\Domain\Plugins\Operations\Input\RemovePluginDataInput;
use App\Domain\Plugins\Operations\MigratePlugin;
use App\Domain\Plugins\Operations\RemovePluginData;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\PluginState;
use App\Domain\Plugins\Queries\PluginList;
use App\Domain\Plugins\Seams\HookSubscriptions;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\RecordingSubscriptions;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
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

    $this->withWaiting = function (): string {
        $waiting = PluginFixtures::copy('tables', [
            'owns' => ['tables' => ['tables_links', 'tables_clicks', 'tables_notes']],
        ], files: [
            'database/migrations/2026_01_03_000000_create_tables_notes.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_notes', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP,
        ]);
        PluginFixtures::use($waiting);
        ($this->enable)();
        $record = PluginRecord::query()->sole();
        $migrations = $record->migrations;
        unset($migrations['2026_01_03_000000_create_tables_notes']);
        $record->fill(['migrations' => $migrations])->save();
        Schema::dropIfExists('tables_notes');

        return $waiting;
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

it('holds back an enabled plugin with a new migration until MigratePlugin runs it', function (): void {
    $third = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables_notes', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP;

    $copy = PluginFixtures::copy('tables', [
        'version' => '1.1.0',
        'owns' => ['tables' => ['tables_links', 'tables_clicks', 'tables_notes']],
    ]);
    PluginFixtures::use($copy);
    ($this->enable)();

    file_put_contents($copy.'/database/migrations/2026_01_03_000000_create_tables_notes.php', $third);
    file_put_contents($copy.'/teme-plugin.json', json_encode([
        ...json_decode((string) file_get_contents($copy.'/teme-plugin.json'), true),
        'version' => '1.1.0',
        'owns' => ['tables' => ['tables_links', 'tables_clicks', 'tables_notes']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    app(PluginRegistry::class)->flush();

    $subscriptions = new RecordingSubscriptions;
    app()->instance(HookSubscriptions::class, $subscriptions);
    app(PluginLoader::class)->load();

    expect(app(LoadedPlugins::class)->state('tests/tables'))->toBe(PluginState::Waiting)
        ->and(app(PluginList::class)->all()[0]->state)->toBe(PluginState::Waiting)
        ->and(app(PluginList::class)->all()[0]->reason)->toBe('Version 1.1.0 has database changes to run.');

    $ran = app(MigratePlugin::class)->execute(
        new MigratePluginInput('tests/tables', null),
    );

    expect($ran)->toBe(['2026_01_03_000000_create_tables_notes'])
        ->and(Schema::hasTable('tables_notes'))->toBeTrue()
        ->and(array_keys(PluginRecord::query()->sole()->migrations))->toContain('2026_01_03_000000_create_tables_notes');

    app(PluginLoader::class)->load();

    expect(app(LoadedPlugins::class)->state('tests/tables'))->toBe(PluginState::Enabled);
});

it('a refused MigratePlugin run leaves the record and tables as they were', function (): void {
    $copy = PluginFixtures::copy('tables', [
        'owns' => ['tables' => ['tables_links', 'tables_clicks', 'tables_notes']],
    ]);
    PluginFixtures::use($copy);
    ($this->enable)();
    $before = PluginRecord::query()->sole()->migrations;

    file_put_contents($copy.'/database/migrations/2026_01_03_000000_create_tables_notes.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP);
    app(PluginRegistry::class)->flush();

    expect(fn () => app(MigratePlugin::class)->execute(
        new MigratePluginInput('tests/tables', null),
    ))->toThrow(ValidationException::class, 'doesn’t start with its prefix');

    expect(PluginRecord::query()->sole()->migrations)->toBe($before)
        ->and(Schema::hasTable('notes'))->toBeFalse()
        ->and(Schema::hasTable('tables_links'))->toBeTrue();
});

it('writes nothing when someone without plugins.manage tries to migrate a plugin', function (?string $role): void {
    ($this->withWaiting)();

    $before = PluginRecord::query()->sole()->migrations;
    $events = DB::table('security_events')->where('type', 'plugin.migrated')->count();
    $actorId = $role === null ? null : (string) User::factory()->create()->assignRole($role)->id;
    $role === null && app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(MigratePlugin::class)->execute(
        new MigratePluginInput('tests/tables', $actorId),
    ))->toThrow(AuthorizationException::class);

    expect(PluginRecord::query()->sole()->migrations)->toBe($before)
        ->and(Schema::hasTable('tables_notes'))->toBeFalse()
        ->and(DB::table('security_events')->where('type', 'plugin.migrated')->count())->toBe($events);
})->with(['editor', 'author', 'nobody at the shell' => null]);

it('asks for the password when MigratePlugin is run without elevation', function (): void {
    ($this->withWaiting)();
    $admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(MigratePlugin::class)->execute(
        new MigratePluginInput('tests/tables', (string) $admin->id),
    ))->toThrow(ElevationRequiredException::class);

    expect(Schema::hasTable('tables_notes'))->toBeFalse();
});

it('runs waiting migrations from the console as the operator, and ends non-zero on a refusal', function (): void {
    ($this->withWaiting)();

    $exit = Artisan::call('teme:plugins:migrate', ['name' => 'tests/tables']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('2026_01_03_000000_create_tables_notes')
        ->and(Schema::hasTable('tables_notes'))->toBeTrue();

    $bad = PluginFixtures::copy('tables', [
        'owns' => ['tables' => ['tables_links', 'tables_clicks', 'tables_extra']],
    ], files: [
        'database/migrations/2026_01_04_000000_create_tables_extra.php' => <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra', function (Blueprint $table): void {
            $table->id();
        });
    }
};
PHP,
    ]);
    PluginFixtures::use($bad);
    app(PluginRegistry::class)->flush();

    $exit = Artisan::call('teme:plugins:migrate', ['name' => 'tests/tables']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('doesn’t start with its prefix')
        ->and(Schema::hasTable('extra'))->toBeFalse();
});

it('refuses RemovePluginData while the plugin is enabled', function (): void {
    PluginFixtures::use('tables');
    ($this->enable)();

    expect(fn () => app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/tables', null)))
        ->toThrow(ValidationException::class, 'Disable it before you remove its data.');

    expect(Schema::hasTable('tables_links'))->toBeTrue()
        ->and(PluginRecord::query()->sole()->migrations)->not->toBe([]);
});

it('refuses RemovePluginData when the plugin has no tables', function (): void {
    PluginFixtures::use('hello');
    ($this->enable)('tests/hello');
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/hello', null));

    expect(fn () => app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/hello', null)))
        ->toThrow(ValidationException::class, 'It has no tables to remove.');
});

it('drops both tables for a disabled plugin, empties the record, and keeps consent', function (): void {
    PluginFixtures::use('tables');
    ($this->enable)();
    $consent = PluginRecord::query()->sole()->consent;
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/tables', null));

    $dropped = app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/tables', null));
    $record = PluginRecord::query()->sole();

    expect($dropped)->toEqualCanonicalizing(['tables_links', 'tables_clicks'])
        ->and(Schema::hasTable('tables_links'))->toBeFalse()
        ->and(Schema::hasTable('tables_clicks'))->toBeFalse()
        ->and($record->migrations)->toBe([])
        ->and($record->consent)->toBe($consent)
        ->and($record->state)->toBe('disabled')
        ->and(DB::table('security_events')->where('type', 'plugin.data_removed')->count())->toBe(1);
});

it('removes recorded tables when the plugin’s code is gone', function (): void {
    $copy = PluginFixtures::copy('tables');
    PluginFixtures::use($copy);
    ($this->enable)();

    // Code gone while the row is still enabled: RemovePluginData must still work.
    (new Filesystem)->deleteDirectory($copy);
    PluginFixtures::use();
    app(PluginRegistry::class)->flush();

    expect(app(PluginRegistry::class)->find('tests/tables'))->toBeNull()
        ->and(PluginRecord::query()->sole()->state)->toBe('enabled');

    $dropped = app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/tables', null));

    expect($dropped)->toEqualCanonicalizing(['tables_links', 'tables_clicks'])
        ->and(Schema::hasTable('tables_links'))->toBeFalse()
        ->and(Schema::hasTable('tables_clicks'))->toBeFalse()
        ->and(PluginRecord::query()->sole()->migrations)->toBe([])
        ->and(Schema::hasTable('users'))->toBeTrue();
});

it('writes nothing when someone without plugins.manage tries to remove plugin data', function (?string $role): void {
    PluginFixtures::use('tables');
    ($this->enable)();
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/tables', null));

    $before = PluginRecord::query()->sole()->migrations;
    $actorId = $role === null ? null : (string) User::factory()->create()->assignRole($role)->id;
    $role === null && app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/tables', $actorId)))
        ->toThrow(AuthorizationException::class);

    expect(Schema::hasTable('tables_links'))->toBeTrue()
        ->and(PluginRecord::query()->sole()->migrations)->toBe($before);
})->with(['editor', 'author', 'nobody at the shell' => null]);

it('creates the tables again when the plugin is enabled after its data was removed', function (): void {
    PluginFixtures::use('tables');
    ($this->enable)();
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/tables', null));
    app(RemovePluginData::class)->execute(new RemovePluginDataInput('tests/tables', null));

    ($this->enable)();

    expect(Schema::hasTable('tables_links'))->toBeTrue()
        ->and(Schema::hasTable('tables_clicks'))->toBeTrue()
        ->and(array_keys(PluginRecord::query()->sole()->migrations))->toHaveCount(2);
});

it('lists the tables and asks unless --force on teme:plugins:remove-data', function (): void {
    PluginFixtures::use('tables');
    ($this->enable)();
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/tables', null));

    $this->artisan('teme:plugins:remove-data', ['name' => 'tests/tables'])
        ->expectsConfirmation('Remove these tables and everything in them?', 'no')
        ->assertSuccessful();

    expect(Schema::hasTable('tables_links'))->toBeTrue();

    $this->artisan('teme:plugins:remove-data', ['name' => 'tests/tables', '--force' => true])
        ->assertSuccessful();

    expect(Schema::hasTable('tables_links'))->toBeFalse()
        ->and(Schema::hasTable('tables_clicks'))->toBeFalse();
});
