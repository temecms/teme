<?php

declare(strict_types=1);

use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Updates\Operations\CancelTemeUpdate;
use App\Domain\Updates\Operations\Input\CancelTemeUpdateInput;
use App\Domain\Updates\Operations\Input\RequestTemeUpdateInput;
use App\Domain\Updates\Operations\RequestTemeUpdate;
use App\Domain\Updates\Queries\TemeUpdates;
use App\Domain\Updates\Requests\UpdateKind;
use App\Domain\Updates\TemeVersion;
use App\Domain\Updates\UpdatePlan;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->insertRow = fn (string $status, string $to = '0.1.0-rc.15', ?string $id = null): string => tap($id ?? strtolower((string) Str::ulid()), fn (string $id) => DB::table('teme_updates')->insert([
        'id' => $id, 'kind' => 'update', 'from_version' => '0.1.0-rc.14', 'to_version' => $to, 'status' => $status,
        'steps' => '[]', 'requested_at' => now(), 'finished_at' => in_array($status, ['requested', 'running'], true) ? null : now(),
    ]));
    $this->refusal = function (UpdateKind $kind, string $version, ?string $ignoring = null): ?string {
        return app(UpdatePlan::class)->refusal($kind, $version, $ignoring);
    };
    $this->ask = function (string $version, UpdateKind $kind = UpdateKind::Update) {
        return fn () => app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput($version, $kind, (string) updAdmin()->id));
    };
});

it('records an update to a listed, newer release in the channel, from the version running', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15', '0.1.0-rc.14']);
    $admin = updAdmin();

    $id = app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput('0.1.0-rc.15', UpdateKind::Update, (string) $admin->id));
    $row = DB::table('teme_updates')->where('id', $id)->first();

    expect($row->kind)->toBe('update')
        ->and($row->from_version)->toBe('0.1.0-rc.14')
        ->and($row->to_version)->toBe('0.1.0-rc.15')
        ->and($row->status)->toBe('requested')
        ->and((int) $row->requested_by)->toBe($admin->id)
        ->and(json_decode((string) $row->steps, true))->toBe([]);
});

it('writes nothing when the person may not manage updates', function (string $role): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15']);
    $person = User::factory()->create()->assignRole($role);
    app()->instance(Elevation::class, new OperatorElevation);

    expect(fn () => app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput('0.1.0-rc.15', UpdateKind::Update, (string) $person->id)))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('teme_updates')->count())->toBe(0);
})->with(['editor', 'author', 'contributor']);

it('writes nothing for a null actor when the operator isn’t present', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15']);

    expect(fn () => app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput('0.1.0-rc.15', UpdateKind::Update, null)))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('teme_updates')->count())->toBe(0);
});

it('asks for the password again, and writes nothing without it', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15']);
    $admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput('0.1.0-rc.15', UpdateKind::Update, (string) $admin->id)))
        ->toThrow(ElevationRequiredException::class);
    expect(DB::table('teme_updates')->count())->toBe(0);
});

it('refuses a version the check never listed', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15']);

    expect(($this->ask)('0.1.0-rc.16'))->toThrow(ValidationException::class, 'Teme 0.1.0-rc.16 isn’t a release this site is offered.');
    expect(DB::table('teme_updates')->count())->toBe(0);
});

it('refuses a version that isn’t newer', function (string $version): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15', '0.1.0-rc.14', '0.1.0-rc.13']);

    expect(($this->ask)($version))->toThrow(ValidationException::class, "Teme {$version} isn’t newer than the 0.1.0-rc.14 this site runs.");
    expect(DB::table('teme_updates')->count())->toBe(0);
})->with(['0.1.0-rc.14', '0.1.0-rc.13']);

it('refuses a pre-release on the stable channel', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15']);
    config(['teme.updates.channel' => 'stable']);

    expect(($this->ask)('0.1.0-rc.15'))->toThrow(ValidationException::class, 'Teme 0.1.0-rc.15 isn’t a release this site is offered.');
    expect(DB::table('teme_updates')->count())->toBe(0);
});

it('refuses a release that needs maintenance mode, or one with any release between that does', function (array $releases, string $named): void {
    updSite('0.1.0-rc.14', $releases);

    expect(($this->ask)('0.1.0-rc.16'))->toThrow(ValidationException::class, "Teme {$named} needs maintenance mode while it deploys, so this update is done by hand: see How to update.");
    expect(DB::table('teme_updates')->count())->toBe(0);
})->with([
    'the target is marked' => [fn () => [updMarked('0.1.0-rc.16'), '0.1.0-rc.15', '0.1.0-rc.14'], '0.1.0-rc.16'],
    'one between is marked' => [fn () => ['0.1.0-rc.16', updMarked('0.1.0-rc.15'), '0.1.0-rc.14'], '0.1.0-rc.15'],
]);

it('refuses an untagged build', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15']);
    config(['teme.tagged' => false]);

    expect(($this->ask)('0.1.0-rc.15'))->toThrow(ValidationException::class, 'This build isn’t a release, so it can’t be updated from here.');
    expect(DB::table('teme_updates')->count())->toBe(0);
});

it('refuses a second request while one is open, and allows one once it has ended', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15']);
    $open = ($this->insertRow)('requested');

    expect(($this->ask)('0.1.0-rc.15'))->toThrow(ValidationException::class, 'An update is already under way.');
    expect(DB::table('teme_updates')->count())->toBe(1);

    DB::table('teme_updates')->where('id', $open)->update(['status' => 'failed', 'finished_at' => now()]);
    (($this->ask)('0.1.0-rc.15'))();

    expect(DB::table('teme_updates')->where('status', 'requested')->count())->toBe(1);
});

it('the database refuses a second open row written by hand', function (): void {
    ($this->insertRow)('requested');

    expect(fn () => ($this->insertRow)('running'))->toThrow(UniqueConstraintViolationException::class);
});

it('the database refuses a version that isn’t a release’s, written by hand', function (string $version): void {
    $accepted = fn (): bool => rescue(fn (): bool => DB::transaction(function () use ($version): bool {
        DB::table('teme_updates')->insert([
            'id' => strtolower((string) Str::ulid()), 'kind' => 'update', 'from_version' => '0.1.0-rc.14', 'to_version' => $version,
            'status' => 'failed', 'steps' => '[]', 'requested_at' => now(),
        ]);

        return true;
    }), false, false);

    expect(TemeVersion::isRelease($version))->toBeFalse()
        ->and($accepted())->toBeFalse()
        ->and(DB::table('teme_updates')->count())->toBe(0);
})->with(['0.1.0-rc.15; rm -rf /', 'latest', 'v0.1.0', '0.1.0-RC.1', '0.1']);

it('the database refuses a version with a trailing newline, which a PHP pattern without D lets through', function (): void {
    expect(fn () => DB::table('teme_updates')->insert([
        'id' => strtolower((string) Str::ulid()), 'kind' => 'update', 'from_version' => '0.1.0-rc.14', 'to_version' => "0.1.0-rc.15\n",
        'status' => 'failed', 'steps' => '[]', 'requested_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('the database accepts the versions TemeVersion accepts', function (string $version): void {
    DB::table('teme_updates')->insert([
        'id' => strtolower((string) Str::ulid()), 'kind' => 'update', 'from_version' => '0.1.0-rc.14', 'to_version' => $version,
        'status' => 'failed', 'steps' => '[]', 'requested_at' => now(),
    ]);

    expect(TemeVersion::isRelease($version))->toBeTrue()
        ->and(DB::table('teme_updates')->count())->toBe(1);
})->with(['0.1.0', '0.1.0-rc.15', '1.20.3', '2.0.0-beta.4', '0.0.1-alpha.0']);

it('re-checks every rule for the request it names, ignoring only that request’s own openness', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15']);
    $id = ($this->insertRow)('requested');

    expect(($this->refusal)(UpdateKind::Update, '0.1.0-rc.15', $id))->toBeNull()
        ->and(($this->refusal)(UpdateKind::Update, '0.1.0-rc.15'))->toBe('An update is already under way.')
        ->and(($this->refusal)(UpdateKind::Update, '0.1.0-rc.16', $id))->toBe('Teme 0.1.0-rc.16 isn’t a release this site is offered.');
});

it('rolls back to the previous release deployed', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15', '0.1.0-rc.14', '0.1.0-rc.13']);
    updDeployed('0.1.0-rc.13', 600);
    $admin = updAdmin();

    $id = app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput('0.1.0-rc.13', UpdateKind::Rollback, (string) $admin->id));
    $row = DB::table('teme_updates')->where('id', $id)->first();

    expect(app(UpdatePlan::class)->previous())->toBe('0.1.0-rc.13')
        ->and($row->kind)->toBe('rollback')
        ->and($row->from_version)->toBe('0.1.0-rc.14')
        ->and($row->to_version)->toBe('0.1.0-rc.13');
});

it('offers no roll back when nothing older was deployed, when the older deploy failed, or when its row isn’t tagged', function (string $case): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.14', '0.1.0-rc.13']);

    match ($case) {
        'nothing older' => null,
        'a failed deploy' => updDeployed('0.1.0-rc.13', 600, [], 4),
        'untagged' => (function (): void {
            updDeployed('0.1.0-rc.13', 600);
            DB::table('deploy_runs')->where('version', '0.1.0-rc.13')->update(['tagged' => false]);
        })(),
    };

    expect(app(UpdatePlan::class)->previous())->toBeNull()
        ->and(($this->ask)('0.1.0-rc.13', UpdateKind::Rollback))->toThrow(ValidationException::class, 'Teme 0.1.0-rc.13 isn’t the release this site ran before, so it can’t be rolled back to.');
})->with(['nothing older', 'a failed deploy', 'untagged']);

it('refuses a roll back across a release that needs maintenance mode, or a deploy that ran such a migration', function (string $case): void {
    updSite('0.1.0-rc.14', $case === 'marked' ? ['0.1.0-rc.15', updMarked('0.1.0-rc.14'), '0.1.0-rc.13'] : ['0.1.0-rc.15', '0.1.0-rc.14', '0.1.0-rc.13']);
    updDeployed('0.1.0-rc.13', 600);

    if ($case === 'ran') {
        DB::table('deploy_runs')->where('version', '0.1.0-rc.14')->update(['report' => json_encode(['migrations' => ['ran' => ['x'], 'needs_maintenance' => ['x']]])]);
    }

    expect(($this->ask)('0.1.0-rc.13', UpdateKind::Rollback))->toThrow(ValidationException::class, $case === 'marked'
        ? 'Teme 0.1.0-rc.14 needs maintenance mode while it deploys, so this update is done by hand: see How to update.'
        : 'A migration since Teme 0.1.0-rc.13 can’t run beside it, so rolling back needs a restore: see How to update.');
    expect(DB::table('teme_updates')->count())->toBe(0);
})->with(['marked', 'ran']);

it('refuses a roll back to a release GitHub no longer lists', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.14']);
    updDeployed('0.1.0-rc.13', 600);

    expect(($this->ask)('0.1.0-rc.13', UpdateKind::Rollback))->toThrow(ValidationException::class, 'Teme 0.1.0-rc.13 is no longer listed on GitHub, so it can’t be deployed again from here.');
});

it('lets the operator request without a password', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15']);
    updOperator();

    $id = app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput('0.1.0-rc.15', UpdateKind::Update, null));

    expect(DB::table('teme_updates')->where('id', $id)->value('requested_by'))->toBeNull();
});

it('cancels a request that waits', function (): void {
    updSite('0.1.0-rc.14', ['0.1.0-rc.15']);
    $admin = updAdmin();
    $id = ($this->insertRow)('requested');

    app(CancelTemeUpdate::class)->execute(new CancelTemeUpdateInput($id, (string) $admin->id));
    $row = DB::table('teme_updates')->where('id', $id)->first();

    expect($row->status)->toBe('cancelled')
        ->and((int) $row->cancelled_by)->toBe($admin->id)
        ->and($row->finished_at)->not->toBeNull()
        ->and(app(TemeUpdates::class)->open())->toBeNull();
});

it('refuses to cancel once the updater has begun, writing nothing', function (): void {
    $admin = updAdmin();
    $id = ($this->insertRow)('running');

    expect(fn () => app(CancelTemeUpdate::class)->execute(new CancelTemeUpdateInput($id, (string) $admin->id)))
        ->toThrow(ValidationException::class, 'The updater has begun this update, so it can’t be cancelled now.');
    expect(DB::table('teme_updates')->where('id', $id)->value('status'))->toBe('running');
});

it('refuses to cancel an update that isn’t there', function (): void {
    $admin = updAdmin();

    expect(fn () => app(CancelTemeUpdate::class)->execute(new CancelTemeUpdateInput(strtolower((string) Str::ulid()), (string) $admin->id)))
        ->toThrow(ValidationException::class, 'There’s no such update.');
});

it('writes nothing when someone without updates.manage cancels', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $id = ($this->insertRow)('requested');

    expect(fn () => app(CancelTemeUpdate::class)->execute(new CancelTemeUpdateInput($id, (string) $editor->id)))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('teme_updates')->where('id', $id)->value('status'))->toBe('requested');
});

it('cancels without a password', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    app()->instance(Elevation::class, new DeniedElevation);
    $id = ($this->insertRow)('requested');

    app(CancelTemeUpdate::class)->execute(new CancelTemeUpdateInput($id, (string) $admin->id));

    expect(DB::table('teme_updates')->where('id', $id)->value('status'))->toBe('cancelled');
});

it('shows the open request, else a finished one for seven days, and never a cancelled one', function (): void {
    $updates = app(TemeUpdates::class);
    $cancelled = ($this->insertRow)('cancelled');
    $done = ($this->insertRow)('done');

    expect($updates->latestShown()?->id)->toBe($done);

    DB::table('teme_updates')->where('id', $done)->update(['finished_at' => now()->subDays(8)]);

    expect($updates->latestShown())->toBeNull();

    $open = ($this->insertRow)('requested');

    expect($updates->latestShown()?->id)->toBe($open)
        ->and($updates->find($cancelled)?->status->value)->toBe('cancelled');
});
