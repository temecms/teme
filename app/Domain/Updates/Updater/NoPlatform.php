<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

/**
 * The platform when none is set up: every call refuses, saying to update by hand.
 */
final class NoPlatform implements Platform
{
    public function name(): string
    {
        return 'No platform';
    }

    public function hasUpdaterOfItsOwn(): bool
    {
        return false;
    }

    public function image(ImageSetting $setting): ?string
    {
        throw PlatformRefused::notSetUp();
    }

    public function setImage(ImageSetting $setting, string $image): void
    {
        throw PlatformRefused::notSetUp();
    }

    public function compose(): PlatformCompose
    {
        throw PlatformRefused::notSetUp();
    }

    public function setCompose(string $compose): void
    {
        throw PlatformRefused::notSetUp();
    }

    public function deploy(string $title): void
    {
        throw PlatformRefused::notSetUp();
    }

    public function latestDeployment(): ?PlatformDeployment
    {
        throw PlatformRefused::notSetUp();
    }

    public function health(): array
    {
        throw PlatformRefused::notSetUp();
    }
}
