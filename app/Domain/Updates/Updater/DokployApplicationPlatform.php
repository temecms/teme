<?php

/**
 * The Dokploy platform for Teme as one Application: every part runs in its one container, so there is one image to
 * change, one deployment to watch, and one container to read. It changes that Application's image with one field,
 * redeploys it, and reads its deployments and its container; it never reads or writes its environment, its
 * registry login or its source. An Application built from a source has no image to change, and says so.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use App\Domain\Updates\ImageName;
use Carbon\CarbonImmutable;
use Throwable;

final class DokployApplicationPlatform implements Platform
{
    /** What Dokploy calls the ways an Application takes its source, in the words people read. */
    private const array SOURCES = [
        'github' => 'its Git repository',
        'gitlab' => 'its Git repository',
        'bitbucket' => 'its Git repository',
        'gitea' => 'its Git repository',
        'git' => 'its Git repository',
        'drop' => 'an uploaded ZIP',
    ];

    private ?string $applicationId = null;

    public function __construct(private readonly DokployApi $api) {}

    public function name(): string
    {
        return 'Dokploy';
    }

    public function hasUpdaterOfItsOwn(): bool
    {
        return false;
    }

    public function image(ImageSetting $setting): ?string
    {
        $image = $this->application()['dockerImage'] ?? null;

        return is_string($image) && $image !== '' ? $image : null;
    }

    public function setImage(ImageSetting $setting, string $image): void
    {
        // The updater is in the container the site is: its one image is the site's.
        if ($setting === ImageSetting::Updater) {
            return;
        }

        if (preg_match(ImageName::IMAGE, $image) !== 1) {
            throw new PlatformRefused('Teme couldn’t change the image alone, so it changed nothing.');
        }

        if ($this->image($setting) === $image) {
            return;
        }

        $this->api->post('application.update', ['applicationId' => $this->id(), 'dockerImage' => $image]);

        if ($this->image($setting) !== $image) {
            throw new PlatformRefused('Dokploy didn’t keep the image Teme sent. Check the Application’s image on its General tab by hand.');
        }
    }

    /**
     * An Application has no compose file: it is reported as one the platform keeps, which the updater leaves alone.
     */
    public function compose(): PlatformCompose
    {
        return new PlatformCompose('', fromGit: true);
    }

    public function setCompose(string $compose): void
    {
        throw new PlatformRefused('A Dokploy Application has no compose file for Teme to replace.');
    }

    public function deploy(string $title): void
    {
        $this->api->post('application.redeploy', ['applicationId' => $this->id(), 'title' => $title, 'description' => 'Asked for by Teme’s updater.']);
    }

    public function latestDeployment(): ?PlatformDeployment
    {
        $rows = $this->api->get('deployment.all', ['applicationId' => $this->id()]);

        if (! is_array($rows)) {
            throw new PlatformRefused('Dokploy answered 200 to deployment.all.');
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
        $application = $this->application();
        $name = $this->appName();
        $query = ['appName' => $name];

        if (isset($application['serverId']) && is_string($application['serverId']) && $application['serverId'] !== '') {
            $query['serverId'] = $application['serverId'];
        }

        $containers = $this->api->get('docker.getContainersByAppNameMatch', $query);

        if (! is_array($containers)) {
            throw new PlatformRefused('Dokploy answered 200 to docker.getContainersByAppNameMatch.');
        }

        $health = $this->containerHealth($containers, $name);

        return [Part::Web->value => $health, Part::Worker->value => $health, Part::WorkerLong->value => $health, Part::Scheduler->value => $health];
    }

    /**
     * Reads the Application as Dokploy holds it, which is refused when it has no image to change; only the
     * source's kind, the image and the server are read, and nothing else is kept or logged.
     *
     * @return array<string, mixed>
     *
     * @throws PlatformRefused
     */
    private function application(): array
    {
        $application = $this->api->get('application.one', ['applicationId' => $this->id()]);

        if (! is_array($application)) {
            throw new PlatformRefused('Dokploy answered 200 to application.one.');
        }

        $source = $application['sourceType'] ?? null;

        if (is_string($source) && isset(self::SOURCES[$source])) {
            throw new PlatformRefused('Dokploy builds this site from '.self::SOURCES[$source].', so Teme can\'t change an image. To update, put the new Teme release in that source, then press Deploy on this Application in Dokploy. docs/guides/operators/platforms/one-container.md, On Dokploy, says how.');
        }

        return $application;
    }

    /**
     * Returns this Application's ID, found once by its App Name among those the key can see.
     *
     * @throws PlatformRefused
     */
    private function id(): string
    {
        if ($this->applicationId !== null) {
            return $this->applicationId;
        }

        $name = $this->appName();
        $found = $this->api->get('application.search', ['appName' => $name, 'limit' => '100']);
        $items = is_array($found) && is_array($found['items'] ?? null) ? $found['items'] : [];

        foreach ($items as $item) {
            // The search matches part of a name: only the exact one is this Application.
            if (is_array($item) && ($item['appName'] ?? null) === $name && is_string($item['applicationId'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $item['applicationId']) === 1) {
                return $this->applicationId = $item['applicationId'];
            }
        }

        throw new PlatformRefused("Dokploy has no Application named {$name} that this API key can see.");
    }

    /**
     * Returns the App Name Dokploy shows on the Application's General tab, checked against the shape it has.
     *
     * @throws PlatformRefused
     */
    private function appName(): string
    {
        $name = config('teme.updater.dokploy.app_name');

        if (! is_string($name) || preg_match('/^[a-zA-Z0-9._-]{1,128}$/D', $name) !== 1) {
            throw new PlatformRefused('TEME_DOKPLOY_APP_NAME isn’t set to this Application’s App Name, which Dokploy shows on its General tab.');
        }

        return $name;
    }

    /**
     * @param  mixed  $row  one row of deployment.all
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
            throw new PlatformRefused('Dokploy answered 200 to deployment.all.');
        }

        try {
            return new PlatformDeployment($id, $status, CarbonImmutable::parse($created), is_string($finished) && $finished !== '' ? CarbonImmutable::parse($finished) : null);
        } catch (Throwable) {
            throw new PlatformRefused('Dokploy answered 200 to deployment.all.');
        }
    }

    /**
     * How the Application's container stands. Dokploy lists every container of that name, the stopped ones of
     * earlier deployments too: only those that run count, and while an old one and a new one run together the
     * worse of the two is the answer, since the new one is the one being waited for.
     *
     * @param  array<mixed>  $containers
     */
    private function containerHealth(array $containers, string $name): PartHealth
    {
        $running = [];

        foreach ($containers as $container) {
            $container = is_array($container) ? $container : [];
            $containerName = ltrim((string) ($container['name'] ?? ''), '/');

            // The listing matches by prefix: "name.1.task" is this Application's, "name-2.1.task" is another's.
            if (($containerName === $name || str_starts_with($containerName, "{$name}.")) && ($container['state'] ?? null) === 'running') {
                $status = (string) ($container['status'] ?? '');
                $running[] = match (true) {
                    str_ends_with($status, '(healthy)') => PartHealth::Healthy,
                    str_ends_with($status, '(health: starting)') => PartHealth::Starting,
                    default => PartHealth::Unhealthy,
                };
            }
        }

        return match (true) {
            $running === [] => PartHealth::Missing,
            in_array(PartHealth::Unhealthy, $running, true) => PartHealth::Unhealthy,
            in_array(PartHealth::Starting, $running, true) => PartHealth::Starting,
            default => PartHealth::Healthy,
        };
    }
}
