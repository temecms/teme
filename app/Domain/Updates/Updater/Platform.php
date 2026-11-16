<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

/**
 * Where Teme runs and how the updater moves it (I-3333): one service with two image settings, a compose file and one deploy. The updater reads and sets an image setting and the compose file, asks the platform to deploy the service, and watches the deployment and each part's health.
 */
interface Platform
{
    /** A short name for people: "Dokploy". */
    public function name(): string;

    /**
     * The image the setting holds now, or null when the platform can't say.
     *
     * @throws PlatformRefused
     */
    public function image(ImageSetting $setting): ?string;

    /**
     * Sets that one setting to the image, every other setting kept exactly; deploys nothing.
     *
     * @throws PlatformRefused
     */
    public function setImage(ImageSetting $setting, string $image): void;

    /**
     * The service's compose file as the platform holds it, and whether the platform takes it from Git.
     *
     * @throws PlatformRefused
     */
    public function compose(): PlatformCompose;

    /**
     * Replaces the service's compose file with this text, every setting kept; deploys nothing. Refuses a file
     * the platform takes from Git.
     *
     * @throws PlatformRefused
     */
    public function setCompose(string $compose): void;

    /**
     * Asks the platform to deploy the whole service with the settings it holds; returns once asked.
     *
     * @throws PlatformRefused
     */
    public function deploy(string $title): void;

    /**
     * The service's newest deployment, or null when it has none.
     *
     * @throws PlatformRefused
     */
    public function latestDeployment(): ?PlatformDeployment;

    /**
     * Whether each long-running part's container runs and passes its health check now, read once for all four.
     *
     * @return array<string, PartHealth> keyed by Part's value: web, worker, worker-long, scheduler
     *
     * @throws PlatformRefused
     */
    public function health(): array;
}
