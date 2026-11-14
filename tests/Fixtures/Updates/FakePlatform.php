<?php

declare(strict_types=1);

namespace Tests\Fixtures\Updates;

use App\Domain\Updates\Updater\ImageSetting;
use App\Domain\Updates\Updater\Part;
use App\Domain\Updates\Updater\PartHealth;
use App\Domain\Updates\Updater\Platform;
use App\Domain\Updates\Updater\PlatformDeployment;
use App\Domain\Updates\Updater\PlatformRefused;
use Carbon\CarbonImmutable;

/**
 * One Compose service in memory for the updater's tests: its two image settings, every call made in
 * order, its deployments, and each part's health, all as the test says.
 */
final class FakePlatform implements Platform
{
    /** @var array<string, string> keyed by ImageSetting's value */
    public array $images = [];

    /** @var list<string> "setImage TEME_IMAGE ghcr.io/…", "deploy Teme 0.1.0-rc.15" */
    public array $calls = [];

    /** @var list<PlatformDeployment> oldest first */
    public array $deployments = [];

    /** What the next deployment ends as: done, error, cancelled or running; null lists nothing (a queued or lost job). */
    public ?string $outcome = 'done';

    /** @var array<string, PartHealth> keyed by Part's value; Healthy when unset */
    public array $health = [];

    /** @var int how many times health() was read, so a test can hold it to once a pass */
    public int $healthReads = 0;

    /** Refuses every call. */
    public ?PlatformRefused $refuse = null;

    /** Refuses deploy() while the image settings still work. */
    public ?PlatformRefused $refuseDeploy = null;

    /** Refuses the next setImage once, after saving the image, as a timeout after the platform saved it would. */
    public ?PlatformRefused $refuseAfterSaving = null;

    public function __construct(string $image)
    {
        foreach (ImageSetting::cases() as $setting) {
            $this->images[$setting->value] = $image;
        }
    }

    public function name(): string
    {
        return 'Fake';
    }

    public function image(ImageSetting $setting): ?string
    {
        $this->guard();

        return $this->images[$setting->value] ?? null;
    }

    public function setImage(ImageSetting $setting, string $image): void
    {
        $this->guard();
        $this->calls[] = "setImage {$setting->value} {$image}";
        $this->images[$setting->value] = $image;

        if ($this->refuseAfterSaving !== null) {
            [$refused, $this->refuseAfterSaving] = [$this->refuseAfterSaving, null];

            throw $refused;
        }
    }

    public function deploy(string $title): void
    {
        $this->guard();

        if ($this->refuseDeploy !== null) {
            throw $this->refuseDeploy;
        }

        $this->calls[] = "deploy {$title}";

        if ($this->outcome !== null) {
            $this->deployments[] = new PlatformDeployment('d'.count($this->calls), $this->outcome, CarbonImmutable::now(), $this->outcome === 'running' ? null : CarbonImmutable::now());
        }
    }

    public function latestDeployment(): ?PlatformDeployment
    {
        $this->guard();

        return $this->deployments === [] ? null : $this->deployments[array_key_last($this->deployments)];
    }

    public function health(): array
    {
        $this->guard();
        $this->healthReads++;

        $all = [];

        foreach ([Part::Web, Part::Worker, Part::WorkerLong, Part::Scheduler] as $part) {
            $all[$part->value] = $this->health[$part->value] ?? PartHealth::Healthy;
        }

        return $all;
    }

    private function guard(): void
    {
        if ($this->refuse !== null) {
            throw $this->refuse;
        }
    }
}
