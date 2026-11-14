<?php

declare(strict_types=1);

namespace App\Domain\Api\Shapes;

/**
 * A media file as the API gives it (D6), wherever it appears: its kind and
 * type, the use's alt text, its size and focal point, its address and its
 * sizes. An image's original is private, so an image's address is its
 * widest size; no storage path, title or upload time is given.
 */
final class MediaShape
{
    /**
     * The file's members, from one CardMedia::forIds() entry with its ID
     * added under `id`; the use's alt text and decorative when it has them,
     * else the library's alt text and not decorative.
     *
     * @param  array{id: string, variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, focalX: int, focalY: int, kind: string, fileUrl: string|null, mimeType: string}  $media
     *
     * @return array<string, mixed>
     */
    public static function of(array $media, ?string $alt = null, ?bool $decorative = null): array
    {
        $image = $media['kind'] === 'image';
        $sizes = [];
        $widest = null;

        if ($image) {
            foreach ($media['variants'] as $name => $variant) {
                $sizes[] = ['name' => (string) $name, 'width' => $variant['width'], 'height' => $variant['height'], 'url' => $variant['url']];

                // The widest w* size, as MediaUrls::largest() picks it.
                if (str_starts_with((string) $name, 'w') && ($widest === null || $variant['width'] > $widest['width'])) {
                    $widest = $variant;
                }
            }

            usort($sizes, fn (array $one, array $other): int => [$one['width'], $one['name']] <=> [$other['width'], $other['name']]);
        }

        return [
            'id' => $media['id'],
            'kind' => $media['kind'],
            'mime_type' => $media['mimeType'],
            'alt' => $alt ?? $media['alt'],
            'decorative' => $decorative ?? false,
            'width' => $image ? $media['width'] : null,
            'height' => $image ? $media['height'] : null,
            'focal' => $image ? ['x' => $media['focalX'], 'y' => $media['focalY']] : null,
            'url' => $image ? ($widest['url'] ?? null) : $media['fileUrl'],
            'sizes' => $sizes,
        ];
    }
}
