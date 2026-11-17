<?php

declare(strict_types=1);

use App\Console\Commands\RunUpdater;
use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Support\Security\Elevation;
use App\Domain\Updates\Operations\Input\RequestTemeUpdateInput;
use App\Domain\Updates\Operations\RequestTemeUpdate;
use App\Domain\Updates\Requests\UpdateKind;
use App\Domain\Updates\Updater\ComposeHash;
use App\Domain\Updates\Updater\ImageSetting;
use App\Domain\Updates\Updater\Platform;
use App\Domain\Updates\Updater\PlatformCompose;
use App\Domain\Updates\Updater\PlatformDeployment;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Tests\Fixtures\Updates\FakePlatform;

uses(RefreshDatabase::class);

const UC_IMAGE = 'ghcr.io/temecms/teme';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    Sleep::fake();
    updSite('0.1.0-rc.14', ['0.1.0-rc.15', '0.1.0-rc.14']);
    config(['teme.updater.image_repository' => UC_IMAGE, 'app.url' => 'https://example.org', 'teme.updater.dokploy.compose_hashes_url' => 'https://templates.example.org/compose-hashes.json']);
    // The template's list names the service's own compose file as current: the update changes only the image.
    Http::fake([
        'https://example.org/up' => Http::response('', 200, ['X-Teme-Version' => '0.1.0-rc.14']),
        'https://templates.example.org/compose-hashes.json' => Http::response(['format' => 1, 'current' => ComposeHash::of(''), 'released' => [ComposeHash::of('')]]),
    ]);
    $this->platform = new FakePlatform(UC_IMAGE.':0.1.0-rc.14');
    app()->instance(Platform::class, $this->platform);
    $this->waiting = function (): string {
        updOperator();

        return (string) app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput('0.1.0-rc.15', UpdateKind::Update, null));
    };
    $this->status = fn (): ?string => DB::table('teme_updates')->value('status');
    // A second session, as another updater's would be: the advisory lock is the database's, not the process's.
    $this->other = function (): ConnectionInterface {
        DB::connectUsing('other_updater', config('database.connections.'.config('database.default')), true);

        return DB::connection('other_updater');
    };
});

afterEach(function (): void {
    DB::purge('other_updater');
});

it('runs one pass with --once, beating its heart and binding the operator', function (): void {
    ($this->waiting)();
    app()->forgetInstance(Elevation::class);

    $this->artisan('teme:updater', ['--once' => true])->assertExitCode(0);

    expect(app(Heartbeats::class)->lastBeat(Heartbeats::UPDATER))->not->toBeNull()
        ->and(($this->status)())->toBe('running')
        ->and($this->platform->calls)->toContain('deploy Teme 0.1.0-rc.15');
});

it('waits 30 seconds idle and 5 while an update runs, and lets the loop’s passes work in between', function (): void {
    $command = app(RunUpdater::class);

    expect($command->wait())->toBe(30);

    ($this->waiting)();
    $command->pass();

    expect($command->wait())->toBe(5)->and(($this->status)())->toBe('running');
});

it('takes the lock at the start of every pass and lets it go at the end', function (): void {
    $other = ($this->other)();
    $command = app(RunUpdater::class);
    $held = fn (): bool => ! $other->selectOne("SELECT pg_try_advisory_lock(hashtext('teme.updater')) AS got")->got;

    $command->pass();

    expect($held())->toBeFalse();
    $other->select("SELECT pg_advisory_unlock(hashtext('teme.updater'))");

    $command->pass();

    expect($held())->toBeFalse();
});

it('waits its turn while another updater holds the lock', function (): void {
    ($this->waiting)();
    $other = ($this->other)();
    $other->select("SELECT pg_advisory_lock(hashtext('teme.updater'))");
    $command = app(RunUpdater::class);

    $command->pass();

    expect(($this->status)())->toBe('requested')->and($this->platform->calls)->toBe([]);

    $other->select("SELECT pg_advisory_unlock(hashtext('teme.updater'))");
    $command->pass();

    expect(($this->status)())->toBe('running');
});

it('carries on after a pass that throws, reporting it and letting the lock go', function (): void {
    Exceptions::fake();
    ($this->waiting)();
    $this->platform->refuse = null;
    $broken = new class($this->platform) implements Platform
    {
        public int $calls = 0;

        public function __construct(private FakePlatform $inner) {}

        public function name(): string
        {
            return 'Broken';
        }

        public function hasUpdaterOfItsOwn(): bool
        {
            return $this->inner->hasUpdaterOfItsOwn();
        }

        public function image(ImageSetting $setting): ?string
        {
            if ($this->calls++ === 0) {
                throw new RuntimeException('The platform fell over.');
            }

            return $this->inner->image($setting);
        }

        public function setImage(ImageSetting $setting, string $image): void
        {
            $this->inner->setImage($setting, $image);
        }

        public function compose(): PlatformCompose
        {
            return $this->inner->compose();
        }

        public function setCompose(string $compose): void
        {
            $this->inner->setCompose($compose);
        }

        public function deploy(string $title): void
        {
            $this->inner->deploy($title);
        }

        public function latestDeployment(): ?PlatformDeployment
        {
            return $this->inner->latestDeployment();
        }

        public function health(): array
        {
            return $this->inner->health();
        }
    };
    app()->instance(Platform::class, $broken);
    $command = app(RunUpdater::class);

    $command->pass();
    Exceptions::assertReported(fn (RuntimeException $error): bool => $error->getMessage() === 'The platform fell over.');

    expect(($this->status)())->toBe('requested');
    $other = ($this->other)();

    expect($other->selectOne("SELECT pg_try_advisory_lock(hashtext('teme.updater')) AS got")->got)->toBeTrue();
    $other->select("SELECT pg_advisory_unlock(hashtext('teme.updater'))");

    $command->pass();

    expect(($this->status)())->toBe('running');
});

it('asks as the operator with teme:updates:request, and prints what it recorded', function (): void {
    $this->artisan('teme:updates:request', ['version' => '0.1.0-rc.15'])
        ->expectsOutput('Update to Teme 0.1.0-rc.15 requested. The updater starts it within a minute.')
        ->assertExitCode(0);

    $row = DB::table('teme_updates')->first();

    expect($row->status)->toBe('requested')->and($row->requested_by)->toBeNull()->and($row->kind)->toBe('update');
});

it('refuses with teme:updates:request in the refusal’s own words, recording nothing', function (): void {
    $this->artisan('teme:updates:request', ['version' => '0.1.0-rc.99'])
        ->expectsOutput('Teme 0.1.0-rc.99 isn’t a release this site is offered.')
        ->assertExitCode(1);

    expect(DB::table('teme_updates')->count())->toBe(0);
});

it('rolls back with teme:updates:request --rollback, to the release before when none is named', function (): void {
    Http::swap(new Factory);
    updSite('0.1.0-rc.14', ['0.1.0-rc.15', '0.1.0-rc.14', '0.1.0-rc.13']);
    updDeployed('0.1.0-rc.13', 600);

    $this->artisan('teme:updates:request', ['--rollback' => true])
        ->expectsOutput('Roll back to Teme 0.1.0-rc.13 requested. The updater starts it within a minute.')
        ->assertExitCode(0);

    expect(DB::table('teme_updates')->first())->toMatchObject(['kind' => 'rollback', 'to_version' => '0.1.0-rc.13']);
});

it('says there is nothing to roll back to when no release was deployed before', function (): void {
    $this->artisan('teme:updates:request', ['--rollback' => true])
        ->expectsOutput('There’s no release this site ran before to roll back to.')
        ->assertExitCode(1);
});

it('cancels the request that waits with teme:updates:cancel', function (): void {
    ($this->waiting)();

    $this->artisan('teme:updates:cancel')->expectsOutput('The update to Teme 0.1.0-rc.15 was cancelled.')->assertExitCode(0);

    expect(($this->status)())->toBe('cancelled');
});

it('says no update is waiting, when none is or the updater has begun it', function (): void {
    $this->artisan('teme:updates:cancel')->expectsOutput('No update is waiting.')->assertExitCode(1);

    ($this->waiting)();
    DB::table('teme_updates')->update(['status' => 'running']);

    $this->artisan('teme:updates:cancel')->expectsOutput('No update is waiting.')->assertExitCode(1);
    expect(($this->status)())->toBe('running');
});
