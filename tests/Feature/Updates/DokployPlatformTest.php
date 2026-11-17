<?php

declare(strict_types=1);

use App\Domain\Updates\Updater\DokployPlatform;
use App\Domain\Updates\Updater\ImageSetting;
use App\Domain\Updates\Updater\NoPlatform;
use App\Domain\Updates\Updater\PartHealth;
use App\Domain\Updates\Updater\Platform;
use App\Domain\Updates\Updater\PlatformRefused;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

const DP_BASE = 'https://dokploy.example.org';
const DP_TOKEN = 'dokploy-secret-token';
const DP_KEY = 'base64:planted-app-key';
const DP_REPOSITORY = 'ghcr.io/temecms/teme';

beforeEach(function (): void {
    config([
        'teme.updater.platform' => 'dokploy',
        'teme.updater.image_repository' => DP_REPOSITORY,
        'teme.updater.dokploy.url' => DP_BASE,
        'teme.updater.dokploy.compose_id' => 'cmp-teme',
        'teme.updater.dokploy.app_name' => 'teme-abc123',
    ]);
    // The key as Settings › Updates keeps it: encrypted, in the updates group.
    dpKey(DP_TOKEN);
    Http::preventStrayRequests();
    $this->old = DP_REPOSITORY.':0.1.0-rc.14';
    $this->new = DP_REPOSITORY.':0.1.0-rc.15';
    $this->env = 'APP_KEY='.DP_KEY."\nTEME_IMAGE={$this->old}\nTEME_UPDATER_IMAGE={$this->old}\nDB_PASSWORD=\"planted password\"\nTEME_DOKPLOY_TOKEN=".DP_TOKEN."\n";
    $this->one = fn (string $env, array $more = []): array => ['composeId' => 'cmp-teme', 'appName' => 'teme-abc123', 'serverId' => null, 'env' => $env, ...$more];
    $this->sent = fn (string $procedure): array => Http::recorded(fn (Request $request): bool => str_contains($request->url(), "/api/{$procedure}"))->all();
    // Every secret that must stay out of what the driver throws, logs or stores.
    $this->secrets = [DP_TOKEN, DP_KEY, 'planted password'];
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.json_encode($event->context);
    });
});

afterEach(function (): void {
    aiServerKey('TEME_DOKPLOY_TOKEN', null);
    // Nothing but the calls the updater needs ever reaches Dokploy: never redeploy, never freshVolumes, and an
    // update of the service names its compose file alone.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'compose.redeploy')
        || (str_contains($request->url(), 'compose.update') && array_keys($request->data()) !== ['composeId', 'composeFile'])
        || str_contains($request->url(), '/api/application.')
        || str_contains(json_encode($request->data()) ?: '', 'freshVolumes'));
});

it('sets TEME_IMAGE by sending the whole environment back with only that value changed, then reads it back', function (): void {
    $after = str_replace('teme:0.1.0-rc.14', 'teme:0.1.0-rc.15', $this->env);
    $after = str_replace('TEME_UPDATER_IMAGE='.$this->new, 'TEME_UPDATER_IMAGE='.$this->old, $after);
    Http::fakeSequence(DP_BASE.'/api/compose.one*')
        ->push(($this->one)($this->env))     // read
        ->push(($this->one)($this->env))     // read again just before writing
        ->push(($this->one)($after));        // read back
    Http::fake([DP_BASE.'/api/compose.saveEnvironment' => Http::response('true', 200)]);

    app(DokployPlatform::class)->setImage(ImageSetting::Site, $this->new);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/compose.saveEnvironment')
        && $request->method() === 'POST'
        && $request->data() === ['composeId' => 'cmp-teme', 'env' => $after]
        && $request->header('x-api-key') === [DP_TOKEN]);
    expect(($this->sent)('compose.one'))->toHaveCount(3);
});

it('refuses, saying so, when Dokploy didn’t keep the environment sent', function (): void {
    Http::fakeSequence(DP_BASE.'/api/compose.one*')
        ->push(($this->one)($this->env))
        ->push(($this->one)($this->env))
        ->push(($this->one)($this->env));    // the old text again
    Http::fake([DP_BASE.'/api/compose.saveEnvironment' => Http::response('true', 200)]);

    expect(fn () => app(DokployPlatform::class)->setImage(ImageSetting::Site, $this->new))
        ->toThrow(PlatformRefused::class, 'Dokploy didn’t keep the environment Teme sent. Check TEME_IMAGE and TEME_UPDATER_IMAGE on the Compose service by hand.');
    expect(($this->sent)('compose.saveEnvironment'))->toHaveCount(1);
});

it('saves nothing when someone changed the environment between its two reads', function (): void {
    Http::fakeSequence(DP_BASE.'/api/compose.one*')
        ->push(($this->one)($this->env))
        ->push(($this->one)($this->env."NEW_SETTING=1\n"));

    expect(fn () => app(DokployPlatform::class)->setImage(ImageSetting::Site, $this->new))
        ->toThrow(PlatformRefused::class, 'Someone changed Teme’s settings in Dokploy while it was updating; nothing was saved.');
    expect(($this->sent)('compose.saveEnvironment'))->toBe([]);
});

it('sends nothing when the setting already holds the image', function (): void {
    Http::fake([DP_BASE.'/api/compose.one*' => Http::response(($this->one)($this->env))]);

    app(DokployPlatform::class)->setImage(ImageSetting::Site, $this->old);

    expect(($this->sent)('compose.saveEnvironment'))->toBe([]);
});

it('reads the image a setting holds, comparing and never echoing the environment', function (): void {
    Http::fake([DP_BASE.'/api/compose.one*' => Http::response(($this->one)($this->env))]);

    expect(app(DokployPlatform::class)->image(ImageSetting::Site))->toBe($this->old)
        ->and(app(DokployPlatform::class)->image(ImageSetting::Updater))->toBe($this->old);
});

it('says nothing about an environment that doesn’t set the image', function (): void {
    Http::fake([DP_BASE.'/api/compose.one*' => Http::response(($this->one)("OTHER=1\n"))]);

    expect(app(DokployPlatform::class)->image(ImageSetting::Site))->toBeNull();
});

it('deploys with compose.deploy, the service’s ID, the title and the description, and nothing else', function (): void {
    Http::fake([DP_BASE.'/api/compose.deploy' => Http::response(['success' => true, 'message' => 'Deployment queued'])]);

    app(DokployPlatform::class)->deploy('Teme 0.1.0-rc.15');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/compose.deploy')
        && $request->data() === ['composeId' => 'cmp-teme', 'title' => 'Teme 0.1.0-rc.15', 'description' => 'Asked for by Teme’s updater.']);
});

it('reads the service’s newest deployment, its ID, state and times', function (): void {
    Http::fake([DP_BASE.'/api/deployment.allByCompose*' => Http::response([
        ['deploymentId' => 'dep-old', 'status' => 'done', 'createdAt' => '2026-11-09T10:00:00.000Z', 'finishedAt' => '2026-11-09T10:05:00.000Z'],
        ['deploymentId' => 'dep-new', 'status' => 'running', 'createdAt' => '2026-11-09T12:00:00.000Z', 'finishedAt' => null],
        ['deploymentId' => 'dep-mid', 'status' => 'error', 'createdAt' => '2026-11-09T11:00:00.000Z', 'finishedAt' => '2026-11-09T11:01:00.000Z'],
    ])]);

    $deployment = app(DokployPlatform::class)->latestDeployment();

    expect($deployment->id)->toBe('dep-new')
        ->and($deployment->state)->toBe('running')
        ->and($deployment->createdAt->toIso8601String())->toBe('2026-11-09T12:00:00+00:00')
        ->and($deployment->finishedAt)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), 'deployment.allByCompose?composeId=cmp-teme'));
});

it('gives null for a service with no deployment', function (): void {
    Http::fake([DP_BASE.'/api/deployment.allByCompose*' => Http::response([])]);

    expect(app(DokployPlatform::class)->latestDeployment())->toBeNull();
});

it('never reads errorMessage', function (): void {
    Http::fake([DP_BASE.'/api/deployment.allByCompose*' => Http::response([[
        'deploymentId' => 'dep-1', 'status' => 'error', 'createdAt' => '2026-11-09T12:00:00.000Z', 'finishedAt' => '2026-11-09T12:01:00.000Z',
        'errorMessage' => 'failed with '.DP_TOKEN.' and '.DP_KEY,
    ]])]);

    $deployment = app(DokployPlatform::class)->latestDeployment();

    foreach ($this->secrets as $secret) {
        expect(json_encode($deployment))->not->toContain($secret);
    }
});

it('reads each part’s health from the service’s containers', function (string $state, string $status, PartHealth $health): void {
    Http::fake([
        DP_BASE.'/api/compose.one*' => Http::response(($this->one)($this->env)),
        DP_BASE.'/api/docker.getContainersByAppNameMatch*' => Http::response([['containerId' => 'c1', 'name' => 'teme-abc123-web-1', 'state' => $state, 'status' => $status]]),
    ]);

    $all = app(DokployPlatform::class)->health();

    expect($all['web'])->toBe($health)
        ->and(array_keys($all))->toBe(['web', 'worker', 'worker-long', 'scheduler'])
        ->and($all['worker'])->toBe(PartHealth::Missing);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'docker.getContainersByAppNameMatch')
        && str_contains($request->url(), 'appName=teme-abc123')
        && str_contains($request->url(), 'appType=docker-compose')
        && ! str_contains($request->url(), 'serverId'));
})->with([
    'healthy' => ['running', 'Up 3 minutes (healthy)', PartHealth::Healthy],
    'starting' => ['running', 'Up 5 seconds (health: starting)', PartHealth::Starting],
    'unhealthy' => ['running', 'Up 2 minutes (unhealthy)', PartHealth::Unhealthy],
    'restarting' => ['restarting', 'Restarting (1) 3 seconds ago', PartHealth::Unhealthy],
    'exited' => ['exited', 'Exited (1) 3 seconds ago', PartHealth::Unhealthy],
]);

it('reads all four parts, and says Missing for a container Docker doesn’t list', function (): void {
    $row = fn (string $part): array => ['containerId' => $part, 'name' => "teme-abc123-{$part}-1", 'state' => 'running', 'status' => 'Up 1 minute (healthy)'];
    Http::fake([
        DP_BASE.'/api/compose.one*' => Http::response(($this->one)($this->env)),
        DP_BASE.'/api/docker.getContainersByAppNameMatch*' => Http::response([$row('web'), $row('worker-long'), $row('scheduler'), $row('deploy')]),
    ]);

    expect(app(DokployPlatform::class)->health())->toBe([
        'web' => PartHealth::Healthy, 'worker' => PartHealth::Missing, 'worker-long' => PartHealth::Healthy, 'scheduler' => PartHealth::Healthy,
    ]);
});

it('reads Missing for everything when Docker lists nothing', function (): void {
    Http::fake([
        DP_BASE.'/api/compose.one*' => Http::response(($this->one)($this->env)),
        DP_BASE.'/api/docker.getContainersByAppNameMatch*' => Http::response([]),
    ]);

    expect(array_values(array_unique(array_map(fn (PartHealth $health): string => $health->name, app(DokployPlatform::class)->health()))))->toBe(['Missing']);
});

it('asks the remote server’s Docker when the service runs on one', function (): void {
    Http::fake([
        DP_BASE.'/api/compose.one*' => Http::response(($this->one)($this->env, ['serverId' => 'srv-1'])),
        DP_BASE.'/api/docker.getContainersByAppNameMatch*' => Http::response([]),
    ]);

    app(DokployPlatform::class)->health();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'docker.getContainersByAppNameMatch') && str_contains($request->url(), 'serverId=srv-1'));
});

it('reads compose.one once for all four parts', function (): void {
    Http::fake([
        DP_BASE.'/api/compose.one*' => Http::response(($this->one)($this->env)),
        DP_BASE.'/api/docker.getContainersByAppNameMatch*' => Http::response([]),
    ]);

    app(DokployPlatform::class)->health();

    expect(($this->sent)('compose.one'))->toHaveCount(1)->and(($this->sent)('docker.getContainersByAppNameMatch'))->toHaveCount(1);
});

it('refuses an appName Dokploy’s own rule wouldn’t take', function (): void {
    Http::fake([DP_BASE.'/api/compose.one*' => Http::response(['composeId' => 'cmp-teme', 'appName' => 'teme abc;id', 'serverId' => null, 'env' => $this->env])]);

    expect(fn () => app(DokployPlatform::class)->health())->toThrow(PlatformRefused::class, 'Dokploy’s Compose service has a name Teme can’t use.');
    expect(($this->sent)('docker.getContainersByAppNameMatch'))->toBe([]);
});

it('never puts the token or the environment’s secrets in an exception, the log or a row, whatever Dokploy answers', function (string $case): void {
    $echo = 'echoing '.DP_TOKEN.' and '.DP_KEY.' and planted password';
    $answer = match ($case) {
        '401' => fn () => Http::response(['message' => $echo], 401),
        '500' => fn () => Http::response($echo, 500),
        '302' => fn () => Http::response($echo, 302, ['Location' => 'https://evil.example/?'.DP_TOKEN]),
        'no connection' => fn () => throw new ConnectionException('cURL error 7: '.$echo),
        'a transfer error' => fn () => throw new TransferException('cURL error 28: '.$echo, new GuzzleHttp\Psr7\Request('GET', DP_BASE.'/api/compose.one')),
        'an unreadable body' => fn () => Http::response($echo, 200),
    };
    Http::fake([DP_BASE.'/api/*' => $answer]);

    try {
        app(DokployPlatform::class)->image(ImageSetting::Site);
        $this->fail('Expected a refusal.');
    } catch (PlatformRefused $refused) {
        $everything = $refused->getMessage().$refused->getTraceAsString().($refused->getPrevious()?->getMessage() ?? '');

        foreach ($this->secrets as $secret) {
            expect($everything)->not->toContain($secret)
                ->and(implode("\n", $this->logged))->not->toContain($secret)
                ->and(json_encode(DB::table('teme_updates')->get()))->not->toContain($secret);
        }

        expect($refused->getPrevious())->toBeNull();
    }
})->with(['401', '500', '302', 'no connection', 'a transfer error', 'an unreadable body']);

it('keeps the environment out of a stack trace', function (): void {
    ini_set('zend.exception_ignore_args', '0');
    Http::fakeSequence(DP_BASE.'/api/compose.one*')->push(($this->one)($this->env))->push(($this->one)($this->env));
    Http::fake([DP_BASE.'/api/compose.saveEnvironment' => Http::response('echo '.DP_KEY, 500)]);

    try {
        app(DokployPlatform::class)->setImage(ImageSetting::Site, $this->new);
        $this->fail('Expected a refusal.');
    } catch (PlatformRefused $refused) {
        // The arguments of the driver's own frames, as an error reporter reads them (getTraceAsString() prints an array as "Array").
        $frames = array_values(array_filter($refused->getTrace(), fn (array $frame): bool => ($frame['class'] ?? '') === DokployPlatform::class));
        $arguments = print_r(array_map(fn (array $frame): array => $frame['args'] ?? [], $frames), true);

        expect($frames)->not->toBe([])->and($arguments)->toContain('SensitiveParameterValue');

        foreach ([DP_TOKEN, DP_KEY, 'planted password'] as $secret) {
            expect($arguments)->not->toContain($secret)->and($refused->getTraceAsString())->not->toContain($secret);
        }

        expect($refused->getMessage())->toBe('Dokploy answered 500 to compose.saveEnvironment.');
    }
});

it('says what each answer means', function (callable $answer, string $sentence): void {
    Http::fake([DP_BASE.'/api/*' => $answer]);

    expect(fn () => app(DokployPlatform::class)->image(ImageSetting::Site))->toThrow(PlatformRefused::class, $sentence);
})->with([
    '401' => [fn () => Http::response('', 401), 'Dokploy refused Teme’s API key. Make a new key in Dokploy and paste it in Settings › Updates.'],
    '403' => [fn () => Http::response('', 403), 'Dokploy refused Teme’s API key. Make a new key in Dokploy and paste it in Settings › Updates.'],
    '404' => [fn () => Http::response('', 404), 'Dokploy can’t find this site’s Compose service.'],
    '302' => [fn () => Http::response('', 302, ['Location' => 'https://elsewhere.example']), 'Dokploy answered 302, sending Teme elsewhere; Teme doesn’t follow it.'],
    '500' => [fn () => Http::response('', 500), 'Dokploy answered 500 to compose.one.'],
    'an unreadable body' => [fn () => Http::response('not json', 200), 'Dokploy answered 200 to compose.one.'],
    'no connection' => [fn () => throw new ConnectionException('refused'), 'Teme couldn’t reach Dokploy at TEME_DOKPLOY_URL.'],
]);

it('refuses an address that isn’t https, and allows http only to a name on Docker’s network', function (string $url, bool $allowed): void {
    config(['teme.updater.dokploy.url' => $url]);
    Http::fake(['*/api/compose.one*' => Http::response(($this->one)($this->env))]);

    if ($allowed) {
        expect(app(DokployPlatform::class)->image(ImageSetting::Site))->toBe($this->old);

        return;
    }

    expect(fn () => app(DokployPlatform::class)->image(ImageSetting::Site))
        ->toThrow(PlatformRefused::class, 'TEME_DOKPLOY_URL must start with https://, or http:// to a name without a dot on Docker’s own network.');
    Http::assertNothingSent();
})->with([
    'https' => ['https://dokploy.example.org', true],
    'https with a path' => ['https://dokploy.example.org/', true],
    'a name on Docker’s network' => ['http://dokploy:3000', true],
    'http to a public name' => ['http://dokploy.example.org', false],
    'ftp' => ['ftp://dokploy.example.org', false],
    'credentials in the address' => ['https://user:pass@dokploy.example.org', false],
    'empty' => ['', false],
]);

it('refuses a compose ID that isn’t Dokploy’s shape, before any request', function (string $id): void {
    config(['teme.updater.dokploy.compose_id' => $id]);

    expect(fn () => app(DokployPlatform::class)->image(ImageSetting::Site))
        ->toThrow(PlatformRefused::class, 'TEME_DOKPLOY_COMPOSE_ID isn’t set to a Dokploy Compose service’s ID.');
    Http::assertNothingSent();
})->with(['cmp teme', '../x', "cmp-teme\n", str_repeat('a', 65)]);

it('refuses to run without a key, before any request, and says where to paste one', function (): void {
    dpKey(null);

    expect(fn () => app(DokployPlatform::class)->deploy('Teme 0.1.0-rc.15'))
        ->toThrow(PlatformRefused::class, 'Teme has no Dokploy API key. Paste one in Settings › Updates.');
    Http::assertNothingSent();
});

it('uses the key set on the server before the one typed in the admin', function (): void {
    aiServerKey('TEME_DOKPLOY_TOKEN', 'dkp_from_the_server');
    Http::fake([DP_BASE.'/api/compose.deploy' => Http::response('{"success":true}', 200)]);

    app(DokployPlatform::class)->deploy('Teme 0.1.0-rc.15');

    Http::assertSent(fn (Request $request): bool => $request->header('x-api-key') === ['dkp_from_the_server']);
});

it('finds its Compose service by app name when no service ID is set, matching the name exactly', function (): void {
    config(['teme.updater.dokploy.compose_id' => null]);
    Http::fake([
        DP_BASE.'/api/compose.search*' => Http::response(['items' => [
            ['composeId' => 'cmp-other', 'appName' => 'teme-abc123-copy', 'sourceType' => 'raw'],
            ['composeId' => 'cmp-teme', 'appName' => 'teme-abc123', 'sourceType' => 'raw'],
        ], 'total' => 2]),
        DP_BASE.'/api/compose.deploy' => Http::response('{"success":true}', 200),
    ]);

    app(DokployPlatform::class)->deploy('Teme 0.1.0-rc.15');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/compose.search') && $request->data()['appName'] === 'teme-abc123');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/compose.deploy') && $request->data()['composeId'] === 'cmp-teme');
});

it('says so when no Compose service has the app name, or more than one has', function (array $items): void {
    config(['teme.updater.dokploy.compose_id' => null]);
    Http::fake([DP_BASE.'/api/compose.search*' => Http::response(['items' => $items, 'total' => count($items)])]);

    expect(fn () => app(DokployPlatform::class)->deploy('Teme 0.1.0-rc.15'))
        ->toThrow(PlatformRefused::class, 'Dokploy has no Compose service named teme-abc123 that Teme’s API key can see. Check TEME_DOKPLOY_APP_NAME, or make the key with access to this service.');
    expect(($this->sent)('compose.deploy'))->toBe([]);
})->with([
    'none' => [[]],
    'only a longer name' => [[['composeId' => 'cmp-other', 'appName' => 'teme-abc123-copy']]],
    'two' => [[['composeId' => 'cmp-a', 'appName' => 'teme-abc123'], ['composeId' => 'cmp-b', 'appName' => 'teme-abc123']]],
]);

it('refuses an app name that isn’t Dokploy’s shape, before any request', function (?string $name): void {
    config(['teme.updater.dokploy.compose_id' => null, 'teme.updater.dokploy.app_name' => $name]);

    expect(fn () => app(DokployPlatform::class)->deploy('Teme 0.1.0-rc.15'))
        ->toThrow(PlatformRefused::class, 'TEME_DOKPLOY_APP_NAME isn’t set to the Compose service’s App Name.');
    Http::assertNothingSent();
})->with([null, '', 'teme abc', '../x', "teme\n"]);

it('reads the service’s compose file, and whether Dokploy takes it from Git', function (string $source, bool $fromGit): void {
    Http::fake([DP_BASE.'/api/compose.one*' => Http::response(($this->one)($this->env, ['composeFile' => "services: {}\n", 'sourceType' => $source]))]);

    $compose = app(DokployPlatform::class)->compose();

    expect($compose->text)->toBe("services: {}\n")
        ->and($compose->fromGit)->toBe($fromGit);
})->with([
    'pasted' => ['raw', false],
    'GitHub' => ['github', true],
    'GitLab' => ['gitlab', true],
    'Bitbucket' => ['bitbucket', true],
    'Gitea' => ['gitea', true],
    'Git' => ['git', true],
]);

it('replaces the compose file with compose.update, naming the file alone, then reads it back', function (): void {
    Http::fakeSequence(DP_BASE.'/api/compose.one*')
        ->push(($this->one)($this->env, ['composeFile' => "old: 1\n", 'sourceType' => 'raw']))
        ->push(($this->one)($this->env, ['composeFile' => "new: 1\n", 'sourceType' => 'raw']));
    Http::fake([DP_BASE.'/api/compose.update' => Http::response('true', 200)]);

    app(DokployPlatform::class)->setCompose("new: 1\n");

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/compose.update')
        && $request->method() === 'POST'
        && $request->data() === ['composeId' => 'cmp-teme', 'composeFile' => "new: 1\n"]);
});

it('sends nothing when the compose file is already the one asked for', function (): void {
    Http::fake([DP_BASE.'/api/compose.one*' => Http::response(($this->one)($this->env, ['composeFile' => "new: 1\n", 'sourceType' => 'raw']))]);

    app(DokployPlatform::class)->setCompose("new: 1\n");

    expect(($this->sent)('compose.update'))->toBe([]);
});

it('refuses, saying so, when Dokploy didn’t keep the compose file sent', function (): void {
    Http::fake([
        DP_BASE.'/api/compose.one*' => Http::response(($this->one)($this->env, ['composeFile' => "old: 1\n", 'sourceType' => 'raw'])),
        DP_BASE.'/api/compose.update' => Http::response('true', 200),
    ]);

    expect(fn () => app(DokployPlatform::class)->setCompose("new: 1\n"))
        ->toThrow(PlatformRefused::class, 'Dokploy didn’t keep the compose file Teme sent. Check the Compose service’s file by hand.');
});

it('never replaces a compose file Dokploy takes from Git', function (): void {
    Http::fake([DP_BASE.'/api/compose.one*' => Http::response(($this->one)($this->env, ['composeFile' => "old: 1\n", 'sourceType' => 'github']))]);

    expect(fn () => app(DokployPlatform::class)->setCompose("new: 1\n"))
        ->toThrow(PlatformRefused::class, 'Dokploy takes this site’s compose file from Git, so Teme leaves it to Dokploy’s Pull.');
    expect(($this->sent)('compose.update'))->toBe([]);
});

it('is the platform when TEME_UPDATER_PLATFORM is dokploy, and NoPlatform otherwise', function (): void {
    expect(app(Platform::class))->toBeInstanceOf(DokployPlatform::class);

    config(['teme.updater.platform' => null]);

    expect(app(Platform::class))->toBeInstanceOf(NoPlatform::class);
});

it('keeps the Dokploy key out of the configuration, Tools › Updates and Settings › Updates', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');

    expect(json_encode(config('teme')))->not->toContain(DP_TOKEN);

    $this->actingAs($admin)->get('/admin/settings/updates')->assertOk()
        ->assertInertia(function (Assert $page): void {
            expect(json_encode($page->toArray()['props']) ?: '')->not->toContain(DP_TOKEN);
        });

    $this->actingAs($admin)->get('/admin/tools/updates')->assertOk()
        ->assertInertia(function (Assert $page): void {
            expect(strtolower(json_encode($page->toArray()['props']) ?: ''))->not->toContain('"token"')->not->toContain(strtolower(DP_TOKEN));
        });
});
