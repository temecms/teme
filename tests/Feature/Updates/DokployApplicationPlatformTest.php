<?php

declare(strict_types=1);

use App\Domain\Updates\Updater\DokployApplicationPlatform;
use App\Domain\Updates\Updater\ImageSetting;
use App\Domain\Updates\Updater\PartHealth;
use App\Domain\Updates\Updater\PlatformRefused;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Settings\SecretReader;

const DA_BASE = 'https://dokploy.example.org';
const DA_TOKEN = 'dokploy-application-secret-key';
const DA_REPOSITORY = 'ghcr.io/temecms/teme';

beforeEach(function (): void {
    config([
        'teme.updater.platform' => 'dokploy-application',
        'teme.updater.image_repository' => DA_REPOSITORY,
        'teme.updater.dokploy.url' => DA_BASE,
        'teme.updater.dokploy.token' => DA_TOKEN,
        'teme.updater.dokploy.app_name' => 'app-teme-x1y2z3',
    ]);
    Http::preventStrayRequests();
    $this->old = DA_REPOSITORY.':0.1.0-rc.14';
    $this->new = DA_REPOSITORY.':0.1.0-rc.15';
    $this->application = fn (array $more = []): array => [
        'applicationId' => 'app-id-1', 'appName' => 'app-teme-x1y2z3', 'sourceType' => 'docker', 'dockerImage' => $this->old,
        'serverId' => null, 'username' => 'registry-user', 'password' => 'registry-password', 'env' => 'APP_KEY=base64:planted', ...$more,
    ];
    $this->found = fn (array $items): array => ['items' => $items, 'total' => count($items)];
    $this->sent = fn (string $procedure): array => Http::recorded(fn (Request $request): bool => str_contains($request->url(), "/api/{$procedure}"))->all();
    $this->secrets = [DA_TOKEN, 'registry-password', 'base64:planted'];
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.json_encode($event->context);
    });
    // Dokploy answers the search and the read of the one Application.
    $this->serve = fn (?array $application = null, ?array $items = null) => Http::fake([
        DA_BASE.'/api/application.search*' => Http::response(($this->found)($items ?? [['applicationId' => 'app-id-1', 'appName' => 'app-teme-x1y2z3']])),
        DA_BASE.'/api/application.one*' => Http::response($application ?? ($this->application)()),
    ]);
});

afterEach(function (): void {
    // Never a registry's login, an environment or a build setting: only the image, a deploy, and reads.
    Http::assertNotSent(fn (Request $request): bool => preg_match('#/api/(application\.(saveEnvironment|saveDockerProvider|saveGitProvider|stop|start|delete|reload)|compose\.)#', $request->url()) === 1);

    foreach ($this->logged as $line) {
        foreach ($this->secrets as $secret) {
            expect($line)->not->toContain($secret);
        }
    }
});

it('finds the Application by the App Name Dokploy shows, and the one exactly, never one that merely contains it', function (): void {
    ($this->serve)(items: [['applicationId' => 'other', 'appName' => 'app-teme-x1y2z3-2'], ['applicationId' => 'app-id-1', 'appName' => 'app-teme-x1y2z3']]);

    expect(app(DokployApplicationPlatform::class)->image(ImageSetting::Site))->toBe($this->old);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/application.search')
        && $request->method() === 'GET'
        && $request->header('x-api-key') === [DA_TOKEN]
        && str_contains($request->url(), 'appName=app-teme-x1y2z3'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/application.one') && str_contains($request->url(), 'applicationId=app-id-1'));
});

it('refuses, saying so, when the key can see no Application of that name', function (): void {
    ($this->serve)(items: [['applicationId' => 'other', 'appName' => 'app-teme-x1y2z3-2']]);

    expect(fn () => app(DokployApplicationPlatform::class)->image(ImageSetting::Site))
        ->toThrow(PlatformRefused::class, 'Dokploy has no Application named app-teme-x1y2z3 that this API key can see.');
});

it('refuses an App Name that isn’t set, or isn’t one, before it asks Dokploy anything', function (?string $name): void {
    config(['teme.updater.dokploy.app_name' => $name]);

    expect(fn () => app(DokployApplicationPlatform::class)->image(ImageSetting::Site))
        ->toThrow(PlatformRefused::class, 'TEME_DOKPLOY_APP_NAME isn’t set to this Application’s App Name, which Dokploy shows on its General tab.');
    Http::assertNothingSent();
})->with([null, '', 'has a space', 'a/slash', 'a?query=1']);

it('reads the image the Application is set to, for both of Teme’s image settings: there is one container', function (): void {
    ($this->serve)();

    expect(app(DokployApplicationPlatform::class)->image(ImageSetting::Site))->toBe($this->old)
        ->and(app(DokployApplicationPlatform::class)->image(ImageSetting::Updater))->toBe($this->old);
});

it('says nothing about an Application that holds no image', function (): void {
    ($this->serve)(($this->application)(['dockerImage' => null]));

    expect(app(DokployApplicationPlatform::class)->image(ImageSetting::Site))->toBeNull();
});

it('refuses an Application Dokploy builds from a source, saying Dokploy rebuilds it and how', function (string $source, string $named): void {
    ($this->serve)(($this->application)(['sourceType' => $source, 'dockerImage' => null]));

    expect(fn () => app(DokployApplicationPlatform::class)->image(ImageSetting::Site))
        ->toThrow(PlatformRefused::class, "Dokploy builds this site from {$named}, so Teme can't change an image. To update, put the new Teme release in that source, then press Deploy on this Application in Dokploy. docs/guides/operators/platforms/one-container.md, On Dokploy, says how.");
    expect(($this->sent)('application.update'))->toBe([]);
})->with([
    'GitHub' => ['github', 'its Git repository'],
    'GitLab' => ['gitlab', 'its Git repository'],
    'Bitbucket' => ['bitbucket', 'its Git repository'],
    'Gitea' => ['gitea', 'its Git repository'],
    'a Git address' => ['git', 'its Git repository'],
    'a ZIP' => ['drop', 'an uploaded ZIP'],
]);

it('changes the image with the one field, and reads it back', function (): void {
    Http::fake([
        DA_BASE.'/api/application.search*' => Http::response(($this->found)([['applicationId' => 'app-id-1', 'appName' => 'app-teme-x1y2z3']])),
        DA_BASE.'/api/application.update' => Http::response('true'),
    ]);
    Http::fakeSequence(DA_BASE.'/api/application.one*')
        ->push(($this->application)())
        ->push(($this->application)(['dockerImage' => $this->new]));

    app(DokployApplicationPlatform::class)->setImage(ImageSetting::Site, $this->new);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/application.update')
        && $request->method() === 'POST'
        && $request->data() === ['applicationId' => 'app-id-1', 'dockerImage' => $this->new]
        && $request->header('x-api-key') === [DA_TOKEN]);
});

it('refuses, saying so, when Dokploy didn’t keep the image sent', function (): void {
    Http::fake([
        DA_BASE.'/api/application.search*' => Http::response(($this->found)([['applicationId' => 'app-id-1', 'appName' => 'app-teme-x1y2z3']])),
        DA_BASE.'/api/application.update' => Http::response('true'),
    ]);
    Http::fakeSequence(DA_BASE.'/api/application.one*')
        ->push(($this->application)())
        ->push(($this->application)());      // still the old image

    expect(fn () => app(DokployApplicationPlatform::class)->setImage(ImageSetting::Site, $this->new))
        ->toThrow(PlatformRefused::class, 'Dokploy didn’t keep the image Teme sent. Check the Application’s image on its General tab by hand.');
});

it('sends nothing when the Application already holds the image', function (): void {
    ($this->serve)();

    app(DokployApplicationPlatform::class)->setImage(ImageSetting::Site, $this->old);

    expect(($this->sent)('application.update'))->toBe([]);
});

it('changes nothing for the updater’s own image setting: the updater is in this container', function (): void {
    app(DokployApplicationPlatform::class)->setImage(ImageSetting::Updater, $this->new);

    Http::assertNothingSent();
});

it('refuses an image that isn’t one Teme builds a name for, sending nothing', function (): void {
    ($this->serve)();

    expect(fn () => app(DokployApplicationPlatform::class)->setImage(ImageSetting::Site, "{$this->new}; rm -rf /"))
        ->toThrow(PlatformRefused::class);
    expect(($this->sent)('application.update'))->toBe([]);
});

it('has no updater of its own to restart', function (): void {
    expect(app(DokployApplicationPlatform::class)->hasUpdaterOfItsOwn())->toBeFalse();
});

it('redeploys the Application, with a title, and nothing else', function (): void {
    ($this->serve)();
    Http::fake([DA_BASE.'/api/application.redeploy' => Http::response('', 200)]);

    app(DokployApplicationPlatform::class)->deploy('Teme 0.1.0-rc.15');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/application.redeploy')
        && $request->method() === 'POST'
        && $request->data() === ['applicationId' => 'app-id-1', 'title' => 'Teme 0.1.0-rc.15', 'description' => 'Asked for by Teme’s updater.']);
});

it('reports the newest deployment', function (): void {
    ($this->serve)();
    Http::fake([DA_BASE.'/api/deployment.all*' => Http::response([
        ['deploymentId' => 'd1', 'status' => 'done', 'createdAt' => '2026-11-10T10:00:00.000Z', 'finishedAt' => '2026-11-10T10:02:00.000Z', 'errorMessage' => 'with '.DA_TOKEN],
        ['deploymentId' => 'd2', 'status' => 'running', 'createdAt' => '2026-11-12T10:00:00.000Z', 'finishedAt' => null],
        ['deploymentId' => 'd0', 'status' => 'error', 'createdAt' => '2026-11-01T10:00:00.000Z', 'finishedAt' => null],
    ])]);

    $latest = app(DokployApplicationPlatform::class)->latestDeployment();

    expect($latest->id)->toBe('d2')->and($latest->state)->toBe('running')->and($latest->finishedAt)->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/deployment.all') && str_contains($request->url(), 'applicationId=app-id-1'));
});

it('reports no deployment when Dokploy lists none', function (): void {
    ($this->serve)();
    Http::fake([DA_BASE.'/api/deployment.all*' => Http::response([])]);

    expect(app(DokployApplicationPlatform::class)->latestDeployment())->toBeNull();
});

it('refuses a deployment list it can’t read', function (): void {
    ($this->serve)();
    Http::fake([DA_BASE.'/api/deployment.all*' => Http::response([['deploymentId' => 'd1', 'status' => 'surprised', 'createdAt' => '2026-11-10T10:00:00.000Z']])]);

    expect(fn () => app(DokployApplicationPlatform::class)->latestDeployment())->toThrow(PlatformRefused::class, 'Dokploy answered 200 to deployment.all.');
});

it('reports the one container as every part: they run in it', function (string $state, string $status, PartHealth $expected): void {
    ($this->serve)();
    Http::fake([DA_BASE.'/api/docker.getContainersByAppNameMatch*' => Http::response([
        ['containerId' => 'c0', 'name' => 'app-teme-x1y2z3-2.1.zzz', 'state' => 'running', 'status' => 'Up 1 hour (healthy)'],
        ['containerId' => 'c1', 'name' => 'app-teme-x1y2z3.1.old', 'state' => 'exited', 'status' => 'Exited (0) 3 minutes ago'],
        ['containerId' => 'c2', 'name' => 'app-teme-x1y2z3.1.new', 'state' => $state, 'status' => $status],
    ])]);

    $health = app(DokployApplicationPlatform::class)->health();

    expect($health)->toBe(['web' => $expected, 'worker' => $expected, 'worker-long' => $expected, 'scheduler' => $expected]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'docker.getContainersByAppNameMatch') && str_contains($request->url(), 'appName=app-teme-x1y2z3') && ! str_contains($request->url(), 'appType'));
})->with([
    'healthy' => ['running', 'Up 2 minutes (healthy)', PartHealth::Healthy],
    'still starting' => ['running', 'Up 5 seconds (health: starting)', PartHealth::Starting],
    'unhealthy' => ['running', 'Up 3 minutes (unhealthy)', PartHealth::Unhealthy],
    'stopped' => ['exited', 'Exited (1) 3 seconds ago', PartHealth::Missing],
]);

it('reports the container missing when none of that name runs, and unhealthy while an old one is healthy beside a new one that isn’t', function (): void {
    ($this->serve)();
    Http::fake([DA_BASE.'/api/docker.getContainersByAppNameMatch*' => Http::sequence()
        ->push([])
        ->push([
            ['containerId' => 'c1', 'name' => 'app-teme-x1y2z3.1.old', 'state' => 'running', 'status' => 'Up 1 hour (healthy)'],
            ['containerId' => 'c2', 'name' => 'app-teme-x1y2z3.1.new', 'state' => 'running', 'status' => 'Up 1 minute (unhealthy)'],
        ])]);

    expect(app(DokployApplicationPlatform::class)->health()['web'])->toBe(PartHealth::Missing)
        ->and(app(DokployApplicationPlatform::class)->health()['web'])->toBe(PartHealth::Unhealthy);
});

it('asks for the containers on the server the Application is on', function (): void {
    ($this->serve)(($this->application)(['serverId' => 'srv-9']));
    Http::fake([DA_BASE.'/api/docker.getContainersByAppNameMatch*' => Http::response([])]);

    app(DokployApplicationPlatform::class)->health();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'docker.getContainersByAppNameMatch') && str_contains($request->url(), 'serverId=srv-9'));
});

it('says in its own words why Dokploy refused, never in Dokploy’s', function (int $status, string $sentence): void {
    Http::fake([DA_BASE.'/api/application.search*' => Http::response(['message' => 'echoes '.DA_TOKEN], $status)]);

    expect(fn () => app(DokployApplicationPlatform::class)->image(ImageSetting::Site))->toThrow(PlatformRefused::class, $sentence);
})->with([
    'a key it refuses' => [401, 'Dokploy refused the API key. Check it in Settings › Updates.'],
    'a key that may not' => [403, 'Dokploy refused the API key. Check it in Settings › Updates.'],
    'an error of its own' => [500, 'Dokploy answered 500 to application.search.'],
]);

it('says it couldn’t reach Dokploy, without the request', function (): void {
    Http::fake([DA_BASE.'/api/application.search*' => fn () => throw new ConnectionException('cURL error with '.DA_TOKEN)]);

    expect(fn () => app(DokployApplicationPlatform::class)->image(ImageSetting::Site))->toThrow(PlatformRefused::class, 'Teme couldn’t reach Dokploy at TEME_DOKPLOY_URL.');

    Http::fake([DA_BASE.'/api/application.search*' => fn () => throw new TransferException('with '.DA_TOKEN)]);

    expect(fn () => app(DokployApplicationPlatform::class)->image(ImageSetting::Site))->toThrow(PlatformRefused::class, 'Teme couldn’t reach Dokploy at TEME_DOKPLOY_URL.');
});

it('sends the key to https, or to http at a name without a dot on Docker’s own network, and nowhere else', function (string $url, bool $allowed): void {
    config(['teme.updater.dokploy.url' => $url]);
    Http::fake(['*' => Http::response(($this->found)([]))]);

    $call = fn () => app(DokployApplicationPlatform::class)->image(ImageSetting::Site);

    $allowed
        ? expect($call)->toThrow(PlatformRefused::class, 'Dokploy has no Application named')
        : expect($call)->toThrow(PlatformRefused::class, 'TEME_DOKPLOY_URL must start with https://');
})->with([
    'https' => ['https://dokploy.example.org', true],
    'http inside Docker' => ['http://dokploy:3000', true],
    'http on the internet' => ['http://dokploy.example.org', false],
    'a login in the address' => ['https://user:pass@dokploy.example.org', false],
    'no address' => ['', false],
]);

it('takes the API key from Settings when the environment holds none', function (): void {
    config(['teme.updater.dokploy.token' => null]);
    app()->instance(SecretReader::class, new class implements SecretReader
    {
        public function secret(string $group, string $key): ?string
        {
            return $group === 'updates' && $key === 'dokploy_api_key' ? 'key-from-settings' : null;
        }
    });
    ($this->serve)();

    app(DokployApplicationPlatform::class)->image(ImageSetting::Site);

    Http::assertSent(fn (Request $request): bool => $request->header('x-api-key') === ['key-from-settings']);
});

it('says so when no key is set anywhere', function (): void {
    config(['teme.updater.dokploy.token' => null]);
    app()->instance(SecretReader::class, new class implements SecretReader
    {
        public function secret(string $group, string $key): ?string
        {
            return null;
        }
    });

    expect(fn () => app(DokployApplicationPlatform::class)->image(ImageSetting::Site))
        ->toThrow(PlatformRefused::class, 'No Dokploy API key is set. Add one in Settings › Updates.');
    Http::assertNothingSent();
});

it('says so when Settings doesn’t know the key yet', function (): void {
    config(['teme.updater.dokploy.token' => null]);
    app()->instance(SecretReader::class, new class implements SecretReader
    {
        public function secret(string $group, string $key): ?string
        {
            throw new InvalidArgumentException('Unknown setting updates.dokploy_api_key');
        }
    });

    expect(fn () => app(DokployApplicationPlatform::class)->image(ImageSetting::Site))
        ->toThrow(PlatformRefused::class, 'No Dokploy API key is set. Add one in Settings › Updates.');
});
