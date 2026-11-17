<?php

declare(strict_types=1);

use App\Domain\Updates\Operations\Input\RequestTemeUpdateInput;
use App\Domain\Updates\Operations\RequestTemeUpdate;
use App\Domain\Updates\Requests\UpdateKind;
use App\Domain\Updates\Updater\UpdateRunner;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

/*
 * The updater's whole path for Teme as one Application on Dokploy: the real
 * runner and the real platform, against a Dokploy in memory that holds one
 * Application, its image, its deployments and its container, and answers as
 * the API does. The site's own /up answers as the test says.
 */

uses(RefreshDatabase::class);

const DU_BASE = 'https://dokploy.example.org';
const DU_REPOSITORY = 'ghcr.io/temecms/teme';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Notification::fake();
    Sleep::fake();
    updSite('0.1.0-rc.14', ['0.1.0-rc.15', '0.1.0-rc.14']);
    config([
        'teme.updater.platform' => 'dokploy-application',
        'teme.updater.image_repository' => DU_REPOSITORY,
        'teme.updater.dokploy.url' => DU_BASE,
        'teme.updater.dokploy.token' => 'dokploy-secret-key',
        'teme.updater.dokploy.app_name' => 'app-teme-x1y2z3',
        'teme.updater.deploy_timeout' => 3600,
        'teme.updater.part_timeout' => 900,
        'app.url' => 'https://example.org',
    ]);
    updOperator();

    $this->old = DU_REPOSITORY.':0.1.0-rc.14';
    $this->new = DU_REPOSITORY.':0.1.0-rc.15';
    $this->site = '0.1.0-rc.14';
    // Dokploy as the test leaves it: what the Application holds, what it has deployed, and every call it was sent.
    $this->dokploy = (object) ['image' => $this->old, 'source' => 'docker', 'deployments' => [], 'calls' => [], 'refuseUpdate' => false, 'deployStatus' => 'done', 'container' => 'Up 5 minutes (healthy)'];
    $this->serve = function (): void {
        Http::swap(new Factory);
        updFake(Http::response([updItem('0.1.0-rc.15'), updItem('0.1.0-rc.14')], 200));
        Http::fake([
            DU_BASE.'/api/*' => function (Request $request) {
                $procedure = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api/'));
                $this->dokploy->calls[] = $procedure;

                return match ($procedure) {
                    'application.search' => Http::response(['items' => [['applicationId' => 'app1', 'appName' => 'app-teme-x1y2z3']], 'total' => 1]),
                    'application.one' => Http::response(['applicationId' => 'app1', 'appName' => 'app-teme-x1y2z3', 'sourceType' => $this->dokploy->source, 'dockerImage' => $this->dokploy->source === 'docker' ? $this->dokploy->image : null, 'serverId' => null]),
                    'application.update' => $this->dokploy->refuseUpdate
                        ? Http::response(['message' => 'refused with dokploy-secret-key'], 500)
                        : tap(Http::response('true'), fn () => $this->dokploy->image = $request->data()['dockerImage']),
                    'application.redeploy' => tap(Http::response('', 200), function () use ($request): void {
                        $this->dokploy->deployments[] = ['deploymentId' => 'dep'.count($this->dokploy->deployments), 'status' => $this->dokploy->deployStatus, 'createdAt' => now()->toIso8601String(), 'finishedAt' => now()->toIso8601String(), 'title' => $request->data()['title']];
                    }),
                    'deployment.all' => Http::response($this->dokploy->deployments),
                    'docker.getContainersByAppNameMatch' => Http::response([['containerId' => 'c1', 'name' => 'app-teme-x1y2z3.1.abc', 'state' => 'running', 'status' => $this->dokploy->container]]),
                    default => Http::response('', 404),
                };
            },
            'https://example.org/up' => fn () => Http::response('', 200, ['X-Teme-Version' => $this->site]),
        ]);
    };
    ($this->serve)();

    $this->request = fn (): string => (string) app(RequestTemeUpdate::class)->execute(new RequestTemeUpdateInput('0.1.0-rc.15', UpdateKind::Update, null));
    $this->tick = fn () => app(UpdateRunner::class)->tick();
    $this->ticks = function (int $times): void {
        foreach (range(1, $times) as $ignored) {
            ($this->tick)();
        }
    };
    $this->row = fn (): object => DB::table('teme_updates')->first();
    $this->states = fn (): array => array_column(json_decode((string) DB::table('teme_updates')->value('steps'), true), 'state', 'step');
    $this->writes = fn (): array => array_values(array_filter($this->dokploy->calls, fn (string $call): bool => in_array($call, ['application.update', 'application.redeploy'], true)));
});

it('carries an update out: one image change, one redeploy, every part watched in the one container, and no updater to restart', function (): void {
    ($this->request)();
    $this->site = '0.1.0-rc.15';
    ($this->tick)();                         // begins: the image set on the Application, the Application redeployed
    updDeployed('0.1.0-rc.15', 0);           // the new container's deploy step records its end
    ($this->ticks)(20);

    $row = ($this->row)();

    expect($row->status)->toBe('done')
        ->and($row->error)->toBeNull()
        ->and($this->dokploy->image)->toBe($this->new)
        ->and(array_values(($this->states)()))->toBe(['done', 'done', 'done', 'done', 'done', 'done', 'done'])
        ->and(($this->writes)())->toBe(['application.update', 'application.redeploy'])
        ->and($this->dokploy->deployments[0]['title'])->toBe('Teme 0.1.0-rc.15');
});

it('sends Dokploy nothing but reads when the Application is built from a source, and ends the request saying Dokploy rebuilds it', function (): void {
    $this->dokploy->source = 'github';
    ($this->request)();

    ($this->ticks)(3);

    $row = ($this->row)();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('Dokploy builds this site from its Git repository, so Teme can\'t change an image. To update, put the new Teme release in that source, then press Deploy on this Application in Dokploy. docs/guides/operators/platforms/one-container.md, On Dokploy, says how.')
        ->and(($this->writes)())->toBe([])
        ->and($this->dokploy->image)->toBe($this->old);
});

it('sends Dokploy nothing when the image on the Application isn’t the one the site runs', function (): void {
    $this->dokploy->image = DU_REPOSITORY.':0.1.0-rc.13';
    ($this->request)();

    ($this->ticks)(3);

    expect(($this->row)()->status)->toBe('failed')
        ->and(($this->writes)())->toBe([]);
});

it('ends the request and changes nothing when Dokploy refuses the image change, without repeating what it said', function (): void {
    $this->dokploy->refuseUpdate = true;
    ($this->request)();

    ($this->ticks)(3);

    $row = ($this->row)();

    expect($row->status)->toBe('failed')
        ->and($row->error)->toBe('Dokploy answered 500 to application.update.')
        ->and($row->error)->not->toContain('dokploy-secret-key')
        ->and($this->dokploy->image)->toBe($this->old)
        ->and(($this->writes)())->toBe(['application.update']);   // the redeploy was never asked
});

it('switches back with one change of the image and one redeploy when the new release never answers', function (): void {
    ($this->request)();
    ($this->tick)();
    updDeployed('0.1.0-rc.15', 0);
    $this->site = '0.1.0-rc.14';             // the old release is what still answers
    ($this->ticks)(2);
    $this->travel(16)->minutes();            // past web's 900 seconds
    ($this->ticks)(8);

    $row = ($this->row)();

    expect($row->status)->toBe('switched_back')
        ->and($row->error)->toBe('web didn’t answer its health check with Teme 0.1.0-rc.15 within 15 minutes.')
        ->and($this->dokploy->image)->toBe($this->old)
        ->and(($this->writes)())->toBe(['application.update', 'application.redeploy', 'application.update', 'application.redeploy'])
        ->and(array_column($this->dokploy->deployments, 'title'))->toBe(['Teme 0.1.0-rc.15', 'Teme 0.1.0-rc.14, switched back']);
});

it('switches back when the container never becomes healthy on the new release', function (): void {
    ($this->request)();
    $this->site = '0.1.0-rc.15';
    ($this->tick)();
    updDeployed('0.1.0-rc.15', 0);
    $this->dokploy->container = 'Up 2 minutes (unhealthy)';
    ($this->ticks)(6);
    $this->travel(16)->minutes();
    $this->site = '0.1.0-rc.14';
    ($this->ticks)(10);

    expect(($this->row)()->status)->toBe('switched_back')
        ->and($this->dokploy->image)->toBe($this->old);
});

it('carries on from the request’s row when the container that began it has been replaced', function (): void {
    ($this->request)();
    ($this->tick)();                         // the old container's updater asks, and is then replaced
    app()->forgetInstance(UpdateRunner::class);
    $this->site = '0.1.0-rc.15';
    config(['teme.version' => '0.1.0-rc.15']);   // the new container's updater runs the new release
    updDeployed('0.1.0-rc.15', 0);
    ($this->ticks)(20);

    expect(($this->row)()->status)->toBe('done')
        ->and(($this->writes)())->toBe(['application.update', 'application.redeploy']);
});
