<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionsAccess;
use App\Domain\Extensions\Sources\ExtensionRequest;
use App\Domain\Extensions\Sources\ExtensionSource;
use App\Domain\Extensions\Sources\PackagistSource;
use App\Domain\Extensions\Sources\SourceKind;
use App\Domain\Extensions\Sources\SourceKinds;
use App\Domain\Security\EncryptedValues;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * A row for extension_requests, open unless the test says otherwise.
 *
 * @param  array<string, mixed>  $values
 *
 * @return array<string, mixed>
 */
function extensionRequestRow(array $values = []): array
{
    return [
        'id' => strtolower((string) Str::ulid()),
        'action' => 'install',
        'kind' => 'plugin',
        'package' => 'acme/link-suggestions',
        'constraint' => '^1.2',
        'source_kind' => 'packagist',
        'status' => 'requested',
        'requested_at' => now(),
        ...$values,
    ];
}

it('refuses a second open request, whatever its status', function (string $second): void {
    DB::table('extension_requests')->insert(extensionRequestRow());

    expect(fn () => DB::table('extension_requests')->insert(extensionRequestRow(['status' => $second])))
        ->toThrow(QueryException::class, 'extension_requests_one_open');
})->with(['requested', 'running', 'waiting']);

it('takes any number of finished requests beside an open one', function (): void {
    DB::table('extension_requests')->insert(extensionRequestRow());

    foreach (['done', 'failed', 'cancelled'] as $status) {
        DB::table('extension_requests')->insert(extensionRequestRow(['status' => $status]));
    }

    expect(DB::table('extension_requests')->count())->toBe(4);
});

it('refuses a rollback or a rebuild that names a package, and an install that names none', function (array $values): void {
    expect(fn () => DB::table('extension_requests')->insert(extensionRequestRow($values)))
        ->toThrow(QueryException::class, 'extension_requests_package');
})->with([
    'rollback with a package' => [['action' => 'rollback']],
    'rebuild with a package' => [['action' => 'rebuild']],
    'install without one' => [['package' => null]],
]);

it('refuses an action, a kind or a status it does not know', function (array $values, string $check): void {
    expect(fn () => DB::table('extension_requests')->insert(extensionRequestRow($values)))
        ->toThrow(QueryException::class, $check);
})->with([
    [['action' => 'upgrade'], 'extension_requests_action'],
    [['kind' => 'module'], 'extension_requests_kind'],
    [['status' => 'paused'], 'extension_requests_status'],
]);

it('refuses a list where an object belongs', function (string $column): void {
    expect(fn () => DB::table('extension_requests')->insert(extensionRequestRow([$column => '[]'])))
        ->toThrow(QueryException::class, 'extension_requests_objects');
})->with(['needs', 'agreed', 'report']);

it('refuses a source kind that is not a key', function (string $kind): void {
    expect(fn () => DB::table('extension_sources')->insert([
        'id' => strtolower((string) Str::ulid()),
        'kind' => $kind,
        'label' => 'Vendor',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'extension_sources_kind');
})->with(['Git', 'x', '9git', 'git-hub', '']);

it('lists the package sources’ keys among the values core encrypts', function (): void {
    $listed = [];

    foreach (app(EncryptedValues::class)->all() as $column) {
        $listed[$column->name()] = [$column->key, $column->label, $column->row];
    }

    expect($listed)->toHaveKey('extension_sources.credential')
        ->and($listed['extension_sources.credential'])->toBe(['id', 'Package sources’ keys', 'package source']);
});

it('gives administrators extensions.install on an existing site, adding only', function (): void {
    $this->seed(RoleSeeder::class);
    Permission::query()->where('name', ExtensionsAccess::INSTALL)->delete();
    Role::findByName('editor')->givePermissionTo('settings.general.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $path = database_path('migrations/2027_07_20_100100_add_extensions_permission.php');
    expect($path)->toBeFile();
    (require $path)->up();
    (require $path)->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::findByName('administrator')->hasPermissionTo(ExtensionsAccess::INSTALL))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo(ExtensionsAccess::INSTALL))->toBeFalse()
        ->and(Role::findByName('editor')->hasPermissionTo('settings.general.manage'))->toBeTrue()
        ->and(Permission::query()->where('name', ExtensionsAccess::INSTALL)->count())->toBe(1);
});

it('gives extensions.install to administrators and no one else', function (): void {
    $this->seed(RoleSeeder::class);

    expect(ExtensionsAccess::INSTALL)->toBe('extensions.install')
        ->and(RoleSeeder::PERMISSIONS)->toContain(ExtensionsAccess::INSTALL);

    foreach (array_keys(RoleSeeder::ROLES) as $role) {
        expect(User::factory()->create()->assignRole($role)->can(ExtensionsAccess::INSTALL))->toBe($role === 'administrator', $role);
    }
});

it('resolves Packagist and a tagged kind by key', function (): void {
    $kind = new class implements SourceKind
    {
        public function key(): string
        {
            return 'vendor_test';
        }

        public function repositories(?ExtensionSource $source, string $extensionsPath): array
        {
            return [];
        }

        public function auth(?ExtensionSource $source): array
        {
            return [];
        }

        public function prepare(ExtensionRequest $request, string $releasePath, string $extensionsPath): void {}
    };

    app()->instance('test.vendor-kind', $kind);
    app()->tag('test.vendor-kind', SourceKinds::TAG);

    $kinds = app(SourceKinds::class);

    expect($kinds->find('packagist'))->toBeInstanceOf(PackagistSource::class)
        ->and($kinds->find('vendor_test'))->toBe($kind)
        ->and($kinds->keys()[0])->toBe('packagist')
        ->and($kinds->keys())->toContain('vendor_test');
});

it('refuses a kind it does not know, and a key registered twice', function (): void {
    expect(fn () => app(SourceKinds::class)->get('directory'))->toThrow(InvalidArgumentException::class, 'directory')
        ->and(app(SourceKinds::class)->find('directory'))->toBeNull();

    app()->bind('test.second-packagist', fn (): PackagistSource => new PackagistSource);
    app()->tag('test.second-packagist', SourceKinds::TAG);

    expect(fn () => app(SourceKinds::class)->keys())->toThrow(UnexpectedValueException::class, 'packagist');
});

it('gives Packagist no repository, no key and nothing to prepare', function (): void {
    $packagist = new PackagistSource;

    expect($packagist->repositories(null, '/var/teme/extensions'))->toBe([])
        ->and($packagist->auth(null))->toBe([]);
});
