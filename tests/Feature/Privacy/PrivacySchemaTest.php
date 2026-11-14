<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('refuses a row each of its checks forbids, by the check’s name', function (array $row, string $constraint): void {
    // Its own savepoint, so the count below still runs once the insert fails.
    $insert = fn () => DB::transaction(fn () => DB::table('privacy_requests')->insert([
        'id' => strtolower((string) Str::ulid()),
        'kind' => 'export',
        'email' => 'ada@example.com',
        'email_hash' => str_repeat('a', 64),
        'note' => 'Asked by email.',
        'created_at' => now(),
        'updated_at' => now(),
        ...$row,
    ]));

    expect($insert)->toThrow(QueryException::class, $constraint);
    expect(DB::table('privacy_requests')->count())->toBe(0);
})->with([
    'a kind it doesn’t know' => [['kind' => 'delete'], 'privacy_requests_kind_check'],
    'a status it doesn’t know' => [['status' => 'waiting'], 'privacy_requests_status_check'],
    'an address in capitals' => [['email' => 'Ada@example.com'], 'privacy_requests_email_check'],
    'a short hash' => [['email_hash' => str_repeat('a', 32)], 'privacy_requests_hash_check'],
    'an empty note' => [['note' => ''], 'privacy_requests_note_check'],
    'an object for results' => [['results' => '{}'], 'privacy_requests_results_check'],
    'an export file on an erasure' => [['kind' => 'erase', 'export_path' => 'privacy/x.json'], 'privacy_requests_export_check'],
    'done with no end' => [['status' => 'done'], 'privacy_requests_ended_check'],
    'a failure on a done request' => [['status' => 'done', 'completed_at' => now(), 'failure' => 'It broke.'], 'privacy_requests_failure_check'],
]);

it('takes a row its checks allow', function (): void {
    DB::table('privacy_requests')->insert([
        'id' => strtolower((string) Str::ulid()),
        'kind' => 'erase',
        'email' => null,
        'email_hash' => str_repeat('0', 64),
        'note' => 'Asked by email.',
        'status' => 'failed',
        'failure' => 'It broke.',
        'completed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('privacy_requests')->value('results'))->toBe('[]');
});

it('gives administrators privacy.manage on an existing site, adding only', function (): void {
    // A site as it stood before privacy.manage existed: RoleSeeder's grants, less it.
    $this->seed(RoleSeeder::class);
    Permission::query()->where('name', 'privacy.manage')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Role::findByName('editor')->givePermissionTo('settings.general.manage');

    $migrate = function (): void {
        (require database_path('migrations/2027_05_01_100100_add_privacy_permission.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };

    $migrate();
    $migrate();

    expect(Role::findByName('administrator')->hasPermissionTo('privacy.manage'))->toBeTrue()
        ->and(Role::findByName('editor')->hasPermissionTo('privacy.manage'))->toBeFalse()
        ->and(Role::findByName('editor')->hasPermissionTo('settings.general.manage'))->toBeTrue()
        ->and(Permission::query()->where('name', 'privacy.manage')->count())->toBe(1)
        ->and(RoleSeeder::PERMISSIONS)->toContain('privacy.manage');
});
