<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Slotted;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Media\HasImageService;
use Teme\Contracts\Media\ImageService;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin that fills the image service slot and gives no address of
 * its own, so the tests see only which plugin the slot chose.
 */
final class Slotted implements HasImageService, ImageService, Plugin
{
    public function register(Hooks $hooks): void {}

    public function imageService(): ImageService
    {
        return $this;
    }

    public function imageAddress(string $mediaId, string $variant, string $format): ?string
    {
        return null;
    }
}
