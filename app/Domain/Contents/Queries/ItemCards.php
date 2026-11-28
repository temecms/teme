<?php

/**
 * A published public item's card for the content reader: its excerpt and
 * featured image from the published revision, with addresses made from the
 * current site address. Never a draft or a private item.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Queries;

use App\Domain\Api\Live;
use App\Domain\Cards\CardMedia;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Hooks\Payloads;
use App\Domain\Media\MediaUrls;
use Teme\Contracts\Content\FeaturedImage;
use Teme\Contracts\Content\ImageSize;
use Teme\Contracts\Content\ItemCard;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\ReadRefused;

final readonly class ItemCards
{
    public function __construct(private CardMedia $media) {}

    /**
     * Returns the published public item's card, or refuses when it isn't one.
     *
     * @throws ReadRefused
     */
    public function of(string $itemId): ItemCard
    {
        $query = Content::query()->whereKey($itemId);
        Live::publicOnly(Live::apply($query->getQuery()));
        $content = $query->first();

        if ($content === null || $content->published_revision_id === null) {
            throw new ReadRefused(__('Only a published public item has a card.'));
        }

        $revision = ContentRevision::query()->whereKey($content->published_revision_id)->firstOrFail(['title', 'fields']);
        $fields = (array) $revision->fields;
        $excerpt = self::text($fields[BuiltInFields::EXCERPT] ?? null);
        $featured = $fields[BuiltInFields::FEATURED_IMAGE] ?? null;
        $mediaId = is_array($featured) && is_string($featured['id'] ?? null) ? (string) $featured['id'] : null;
        $item = Payloads::item($content);

        return new ItemCard(
            new ItemRef(
                $item->id,
                $item->type,
                (string) $revision->title,
                $item->status,
                $item->url,
                $item->language,
                $item->indexable,
                $item->submission,
            ),
            $excerpt,
            $mediaId === null ? null : $this->featured($mediaId),
        );
    }

    /**
     * The featured image's public address and sizes, or null when the media
     * is missing or in the trash.
     */
    private function featured(string $mediaId): ?FeaturedImage
    {
        $entry = $this->media->forIds([$mediaId])[$mediaId] ?? null;

        if ($entry === null || $entry['kind'] !== 'image') {
            return null;
        }

        $sizes = [];
        $widest = null;

        foreach ($entry['variants'] as $name => $variant) {
            $sizes[] = new ImageSize((string) $name, $variant['width'], $variant['height'], $variant['url']);

            if (str_starts_with((string) $name, 'w') && ($widest === null || $variant['width'] > $widest['width'])) {
                $widest = $variant;
            }
        }

        usort($sizes, fn (ImageSize $one, ImageSize $other): int => [$one->width, $one->name] <=> [$other->width, $other->name]);

        $address = $widest['url'] ?? MediaUrls::largest(
            array_map(fn (array $variant): array => [
                'path' => $variant['path'],
                'width' => $variant['width'],
                'height' => $variant['height'],
                'bytes' => $variant['bytes'],
            ], $entry['variants']),
            $mediaId,
        );

        if ($address === null || $address === '') {
            return null;
        }

        return new FeaturedImage($mediaId, $address, $sizes, is_string($entry['alt'] ?? null) ? self::text($entry['alt']) : null);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
