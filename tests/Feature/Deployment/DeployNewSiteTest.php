<?php

declare(strict_types=1);

use App\Domain\Deployment\Health\Doctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\Deployment\DeployRun;
use Tests\Fixtures\Deployment\RestrictedRole;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DeployRun::isolatePageCache();
    $this->role = null;
});

afterEach(function (): void {
    $this->role?->close();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('installs a new site as production\'s role: every migration, Teme\'s roles from theirs, no backup asked of tables not made yet, a flagged migration without maintenance mode, and the doctor\'s checks run again once their tables exist (I-377)', function (): void {
    $this->role = RestrictedRole::open();
    app('migrator')->path(DeployRun::migrations('flagged'));
    $all = array_keys(app('migrator')->getMigrationFiles([...app('migrator')->paths(), database_path('migrations')]));

    // The real doctor: its heartbeat checks read tables this database doesn't have yet.
    $run = DeployRun::here(['--json' => true]);
    $findings = collect($run->json['findings'] ?? []);

    expect($run->status)->toBe(0)
        ->and($run->json['migrations'])->toBe(['new_database' => true, 'pending' => $all, 'ran' => $all, 'needs_maintenance' => [], 'error' => null])
        ->and($run->json['backup']['state'])->toBe('not-set-up')
        ->and($run->json['steps'])->toMatchArray(['backup' => 'skipped', 'migrate' => 'done', 'recheck' => 'done'])
        ->and(Role::query()->orderBy('name')->pluck('name')->all())->toBe(['administrator', 'author', 'contributor', 'editor'])
        ->and($findings->where('severity', 'error')->all())->toBe([])
        ->and($findings->firstWhere('check', 'scheduler'))->toMatchArray([
            'severity' => 'warning',
            'message' => Doctor::NOT_YET,
        ]);
});
