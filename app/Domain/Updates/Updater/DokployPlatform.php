<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * The Dokploy platform for Teme's one Compose service (I-3333): sets one image line of its environment, deploys it, and
 * reads its deployments and its containers. It holds the owner's Dokploy token and the service's whole environment
 * text, which carries every secret, and never lets either reach an exception, a log line, a row or a title.
 */
final class DokployPlatform implements Platform
{
    /** The four parts whose containers Dokploy lists, in the order the updater watches them. */
    private const array WATCHED = [Part::Web, Part::Worker, Part::WorkerLong, Part::Scheduler];

    public function name(): string
    {
        return 'Dokploy';
    }

    public function image(ImageSetting $setting): ?string
    {
        return DokployEnvironment::image($this->environment(), $setting);
    }

    public function setImage(ImageSetting $setting, string $image): void
    {
        $before = $this->environment();
        $after = DokployEnvironment::with($before, $setting, $image);

        if ($after === $before) {
            return;
        }

        // Dokploy has no call that sets one variable: a save in the panel between the two reads is caught here, and one after the write below.
        if ($this->environment() !== $before) {
            throw new PlatformRefused('Someone changed Teme’s settings in Dokploy while it was updating; nothing was saved.');
        }

        $this->call('POST', 'compose.saveEnvironment', ['composeId' => $this->composeId(), 'env' => $after]);

        if ($this->environment() !== $after) {
            throw new PlatformRefused('Dokploy didn’t keep the environment Teme sent. Check TEME_IMAGE and TEME_UPDATER_IMAGE on the Compose service by hand.');
        }
    }

    public function deploy(string $title): void
    {
        $this->call('POST', 'compose.deploy', ['composeId' => $this->composeId(), 'title' => $title, 'description' => 'Asked for by Teme’s updater.']);
    }

    public function latestDeployment(): ?PlatformDeployment
    {
        $rows = $this->call('GET', 'deployment.allByCompose', ['composeId' => $this->composeId()]);

        if (! is_array($rows)) {
            throw new PlatformRefused('Dokploy answered 200 to deployment.allByCompose.');
        }

        $latest = null;

        foreach ($rows as $row) {
            $deployment = $this->deployment($row);

            if ($latest === null || $deployment->createdAt->gt($latest->createdAt)) {
                $latest = $deployment;
            }
        }

        return $latest;
    }

    public function health(): array
    {
        $service = $this->service();
        $appName = $service['appName'] ?? null;

        if (! is_string($appName) || preg_match('/^[a-zA-Z0-9._-]+$/D', $appName) !== 1) {
            throw new PlatformRefused('Dokploy’s Compose service has a name Teme can’t use.');
        }

        $query = ['appName' => $appName, 'appType' => 'docker-compose'];

        if (isset($service['serverId']) && is_string($service['serverId']) && $service['serverId'] !== '') {
            $query['serverId'] = $service['serverId'];
        }

        $containers = $this->call('GET', 'docker.getContainersByAppNameMatch', $query);

        if (! is_array($containers)) {
            throw new PlatformRefused('Dokploy answered 200 to docker.getContainersByAppNameMatch.');
        }

        $health = [];

        foreach (self::WATCHED as $part) {
            $health[$part->value] = $this->partHealth($containers, "{$appName}-{$part->value}-1");
        }

        return $health;
    }

    /**
     * Reads the environment text of the service, which is held for the length of one call and never kept.
     *
     * @throws PlatformRefused
     */
    private function environment(): string
    {
        $env = $this->service()['env'] ?? null;

        if (! is_string($env)) {
            throw new PlatformRefused('Dokploy answered 200 to compose.one.');
        }

        return $env;
    }

    /**
     * Reads the service as Dokploy holds it; its other fields are never read, logged or kept.
     *
     * @return array<string, mixed>
     *
     * @throws PlatformRefused
     */
    private function service(): array
    {
        $service = $this->call('GET', 'compose.one', ['composeId' => $this->composeId()]);

        if (! is_array($service)) {
            throw new PlatformRefused('Dokploy answered 200 to compose.one.');
        }

        return $service;
    }

    /**
     * @param  mixed  $row  one row of deployment.allByCompose
     *
     * @throws PlatformRefused
     */
    private function deployment(mixed $row): PlatformDeployment
    {
        // errorMessage is never read: it is Dokploy's text, which may echo what it was sent.
        $id = is_array($row) ? ($row['deploymentId'] ?? null) : null;
        $status = is_array($row) ? ($row['status'] ?? null) : null;
        $created = is_array($row) ? ($row['createdAt'] ?? null) : null;
        $finished = is_array($row) ? ($row['finishedAt'] ?? null) : null;

        if (! is_string($id) || ! in_array($status, [PlatformDeployment::RUNNING, PlatformDeployment::DONE, PlatformDeployment::ERROR, PlatformDeployment::CANCELLED], true) || ! is_string($created)) {
            throw new PlatformRefused('Dokploy answered 200 to deployment.allByCompose.');
        }

        try {
            return new PlatformDeployment($id, $status, CarbonImmutable::parse($created), is_string($finished) && $finished !== '' ? CarbonImmutable::parse($finished) : null);
        } catch (Throwable) {
            throw new PlatformRefused('Dokploy answered 200 to deployment.allByCompose.');
        }
    }

    /**
     * @param  array<mixed>  $containers
     */
    private function partHealth(array $containers, string $name): PartHealth
    {
        foreach ($containers as $container) {
            if (! is_array($container) || ltrim((string) ($container['name'] ?? ''), '/') !== $name) {
                continue;
            }

            $status = (string) ($container['status'] ?? '');

            return match (true) {
                ($container['state'] ?? null) === 'running' && str_ends_with($status, '(healthy)') => PartHealth::Healthy,
                ($container['state'] ?? null) === 'running' && str_ends_with($status, '(health: starting)') => PartHealth::Starting,
                default => PartHealth::Unhealthy,
            };
        }

        return PartHealth::Missing;
    }

    /**
     * Returns the Compose service's ID, checked against the shape Dokploy gives them.
     *
     * @throws PlatformRefused
     */
    private function composeId(): string
    {
        $id = config('teme.updater.dokploy.compose_id');

        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1) {
            throw new PlatformRefused('TEME_DOKPLOY_COMPOSE_ID isn’t set to a Dokploy Compose service’s ID.');
        }

        return $id;
    }

    /**
     * Returns the address of one API procedure: https, or http to a name without a dot on Docker's own network.
     *
     * @throws PlatformRefused
     */
    private function address(string $procedure): string
    {
        $url = config('teme.updater.dokploy.url');
        $parts = is_string($url) ? parse_url($url) : false;
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $secure = $scheme === 'https' || ($scheme === 'http' && $host !== '' && ! str_contains($host, '.'));

        if (! is_string($url) || ! is_array($parts) || $host === '' || ! $secure || isset($parts['user']) || isset($parts['pass'])) {
            throw new PlatformRefused('TEME_DOKPLOY_URL must start with https://, or http:// to a name without a dot on Docker’s own network.');
        }

        return rtrim($url, '/')."/api/{$procedure}";
    }

    /**
     * Calls one procedure. Every failure becomes a PlatformRefused built from Teme's own words and the status, since
     * Dokploy's body, and Laravel's exception carrying it, may echo the token or the environment.
     *
     * @param  array<string, string>  $data
     *
     * @throws PlatformRefused
     */
    private function call(string $method, string $procedure, #[SensitiveParameter] array $data): mixed
    {
        $token = config('teme.updater.dokploy.token');

        if (! is_string($token) || $token === '') {
            throw new PlatformRefused('TEME_DOKPLOY_TOKEN isn’t set on the updater.');
        }

        $address = $this->address($procedure);

        try {
            $response = Http::withHeaders(['x-api-key' => $token])
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(30)
                ->send($method, $address, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
        } catch (ConnectionException|TransferException) {
            // TransferException is Guzzle's root: whatever the transport throws, its message may carry the request.
            throw new PlatformRefused('Teme couldn’t reach Dokploy at TEME_DOKPLOY_URL.');
        }

        // Words built here alone: Dokploy's body may echo what it was sent, and compose.one carries every secret.
        return match (true) {
            $response->successful() => $this->readable($response->json(), $response->body(), $response->status(), $procedure),
            in_array($response->status(), [401, 403], true) => throw new PlatformRefused('Dokploy refused the token in TEME_DOKPLOY_TOKEN.'),
            $response->status() === 404 => throw new PlatformRefused('Dokploy can’t find the Compose service in TEME_DOKPLOY_COMPOSE_ID.'),
            $response->redirect() => throw new PlatformRefused("Dokploy answered {$response->status()}, sending Teme elsewhere; Teme doesn’t follow it."),
            default => throw new PlatformRefused("Dokploy answered {$response->status()} to {$procedure}."),
        };
    }

    /**
     * Returns the decoded body of a 2xx answer; the calls that write need no body, the ones that read need it to be JSON.
     */
    private function readable(#[SensitiveParameter] mixed $json, #[SensitiveParameter] string $body, int $status, string $procedure): mixed
    {
        $writes = in_array($procedure, ['compose.saveEnvironment', 'compose.deploy'], true);

        if (! $writes && $json === null && trim($body) !== 'null') {
            throw new PlatformRefused("Dokploy answered {$status} to {$procedure}.");
        }

        return $json;
    }
}
