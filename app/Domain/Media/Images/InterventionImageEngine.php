<?php

/**
 * The ImageEngine on Intervention Image 4, with the GD or Imagick driver. It
 * applies the camera's orientation, strips metadata, and turns animations
 * into stills from their first frame.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Images;

use App\Domain\Media\MediaVariants;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ExceptionInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

final class InterventionImageEngine implements ImageEngine
{
    private readonly ImageManager $manager;

    public function __construct(string $driver)
    {
        $this->manager = new ImageManager(
            $driver === 'imagick' ? ImagickDriver::class : GdDriver::class,
            autoOrientation: true,
            decodeAnimation: true,
            strip: true,
        );
    }

    public function canDecode(string $mimeType): bool
    {
        if (! str_starts_with($mimeType, 'image/')) {
            return false;
        }

        // supports() checks both the driver and how the PHP extension was
        // built, so a GD built without AVIF answers false for AVIF.
        try {
            return $this->manager->driver->supports($mimeType);
        } catch (Throwable) {
            return false;
        }
    }

    public function inspect(string $path): ?array
    {
        // A frame GD can't read warns rather than throws (intervention/gif's
        // Splitter calls imagecreatefromstring() without @), and Laravel makes
        // the warning an ErrorException.
        try {
            $image = $this->manager->decodePath($path);
        } catch (Throwable) {
            return null;
        }

        return ['width' => $image->width(), 'height' => $image->height(), 'animated' => $image->isAnimated()];
    }

    public function variants(string $source, array $widths, int $thumbnail, int $focalX, int $focalY, string $directory, array $share = []): array
    {
        $image = $this->manager->decodePath($source);

        if ($image->isAnimated()) {
            $image->removeAnimation();
        }

        $made = [];

        foreach ($widths as $width) {
            $made["w{$width}"] = $this->write((clone $image)->scaleDown(width: $width), $directory, "w{$width}");
        }

        [$resizedWidth, $resizedHeight, $x, $y] = FocalCrop::square($image->width(), $image->height(), $thumbnail, $focalX, $focalY);
        $made['thumb'] = $this->write((clone $image)->resize($resizedWidth, $resizedHeight)->crop($thumbnail, $thumbnail, x: $x, y: $y), $directory, 'thumb');

        foreach ($share as $key => [$shareWidth, $shareHeight]) {
            [$cropWidth, $cropHeight] = MediaVariants::shareSize($image->width(), $image->height(), $shareWidth, $shareHeight);
            [$resizedWidth, $resizedHeight, $x, $y] = FocalCrop::fit($image->width(), $image->height(), $cropWidth, $cropHeight, $focalX, $focalY);
            $made[$key] = $this->write((clone $image)->resize($resizedWidth, $resizedHeight)->crop($cropWidth, $cropHeight, x: $x, y: $y), $directory, $key);
        }

        return $made;
    }

    public function reencode(string $bytes, int $longestSide, int $quality): ?array
    {
        try {
            $image = $this->manager->decodeBinary($bytes);

            if ($image->isAnimated()) {
                $image->removeAnimation();
            }

            $longest = max($image->width(), $image->height());

            if ($longest > $longestSide) {
                // Both sides held to the limit, so the shape holds and the longer side lands on it.
                $image->scaleDown(width: $longestSide, height: $longestSide);
            }

            $encoded = (string) $image->encodeUsingFormat(Format::WEBP, quality: $quality);
        } catch (ExceptionInterface) {
            return null;
        }

        return ['bytes' => $encoded, 'width' => $image->width(), 'height' => $image->height()];
    }

    /**
     * @return array{file: string, width: int, height: int, bytes: int}
     */
    private function write(ImageInterface $image, string $directory, string $key): array
    {
        $file = $directory.DIRECTORY_SEPARATOR.$key.'.webp';
        $bytes = (string) $image->encodeUsingFormat(Format::WEBP, quality: MediaVariants::QUALITY);
        file_put_contents($file, $bytes);

        return ['file' => $file, 'width' => $image->width(), 'height' => $image->height(), 'bytes' => strlen($bytes)];
    }
}
