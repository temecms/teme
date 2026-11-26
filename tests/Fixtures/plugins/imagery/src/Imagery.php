<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Imagery;

use Closure;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Media\HasImageService;
use Teme\Contracts\Media\ImageService;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin with an image service. The tests set what its driver
 * answers and read what it was asked.
 */
final class Imagery implements HasImageService, ImageService, Plugin
{
    /** @var (Closure(string, string, string): ?string)|null */
    public static ?Closure $address = null;

    /** @var list<array{string, string, string}> */
    public static array $asked = [];

    public static function reset(): void
    {
        self::$address = null;
        self::$asked = [];
    }

    public function register(Hooks $hooks): void {}

    public function imageService(): ImageService
    {
        return $this;
    }

    public function imageAddress(string $mediaId, string $variant, string $format): ?string
    {
        self::$asked[] = [$mediaId, $variant, $format];

        return self::$address === null
            ? "https://images.example.com/{$mediaId}/{$variant}.{$format}"
            : (self::$address)($mediaId, $variant, $format);
    }
}
