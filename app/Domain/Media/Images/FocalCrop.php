<?php

declare(strict_types=1);

namespace App\Domain\Media\Images;

/**
 * The arithmetic of a crop that keeps the focal point in view: resize
 * so the shorter side fills the square, then place the square as close to
 * centred on the focal point as the edges allow.
 */
final class FocalCrop
{
    /**
     * @return array{0: int, 1: int, 2: int, 3: int} resized width, resized height, crop x, crop y
     */
    public static function square(int $width, int $height, int $size, int $focalX, int $focalY): array
    {
        return self::fit($width, $height, $size, $size, $focalX, $focalY);
    }

    /**
     * The same for a crop of any shape: resize so both sides fill the crop,
     * then place it as close to centred on the focal point as the edges allow.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} resized width, resized height, crop x, crop y
     */
    public static function fit(int $width, int $height, int $cropWidth, int $cropHeight, int $focalX, int $focalY): array
    {
        $factor = max($cropWidth / $width, $cropHeight / $height);
        $resizedWidth = max($cropWidth, (int) round($width * $factor));
        $resizedHeight = max($cropHeight, (int) round($height * $factor));

        $place = fn (int $length, int $crop, int $focal): int => max(0, min($length - $crop, (int) round($length * $focal / 100 - $crop / 2)));

        return [$resizedWidth, $resizedHeight, $place($resizedWidth, $cropWidth, $focalX), $place($resizedHeight, $cropHeight, $focalY)];
    }
}
