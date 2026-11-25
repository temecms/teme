<?php

/**
 * Swaps a post's outside pictures for the library's once their files are
 * here (#716): each Image card at the old site's address whose file the
 * import brought becomes that library item, keeping its words, and the
 * featured image likewise. What the post still waits for is what is left.
 *
 * @package Teme
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Media;

use App\Domain\Cards\Types\ImageCard;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\Media\ImageTrace;
use App\Domain\Import\Convert\Media\MediaTargets;

final class PictureSwap
{
    /**
     * The content with each outside picture whose file is in the library
     * swapped for it, at any depth.
     *
     * @param  list<array<string, mixed>>  $content
     *
     * @return list<array<string, mixed>>
     */
    public static function document(array $content, ConversionSite $site, MediaTargets $media): array
    {
        return array_map(fn (array $node): array => self::node($node, $site, $media), $content);
    }

    /**
     * The fields with the featured image swapped for the library's: one at
     * the old site's address by its file, or none yet by the attachment the
     * post named.
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array<string, mixed>
     */
    public static function featured(array $fields, ?int $attachmentId, ConversionSite $site, MediaTargets $media): array
    {
        $value = $fields[BuiltInFields::FEATURED_IMAGE] ?? null;
        $outside = is_array($value) && ($value['id'] ?? null) === null && is_string($value['source'] ?? null) ? $value['source'] : null;

        if (is_array($value) && $outside === null) {
            return $fields;
        }

        $found = $attachmentId === null ? null : $media->attachment($attachmentId);
        $key = $outside === null ? null : $site->uploadsKey($outside);
        $found ??= $key === null ? null : ImageTrace::lookup($key, $media);

        if ($found === null) {
            return $fields;
        }

        $alt = is_array($value) && is_string($value['alt'] ?? null) && trim($value['alt']) !== '' ? trim($value['alt']) : null;
        $fields[BuiltInFields::FEATURED_IMAGE] = ['id' => $found, 'alt' => $alt, 'decorative' => false];

        return $fields;
    }

    /**
     * The waiting keys whose file isn't in the library yet.
     *
     * @param  list<string>  $keys
     *
     * @return list<string>
     */
    public static function left(array $keys, MediaTargets $media): array
    {
        return array_values(array_filter($keys, function (string $key) use ($media): bool {
            if (str_starts_with($key, 'a:')) {
                return $media->attachment((int) substr($key, 2)) === null;
            }

            return ! str_starts_with($key, 'f:') || ImageTrace::lookup(substr($key, 2), $media) === null;
        }));
    }

    /**
     * The waiting keys a revision still needs: the files of the pictures it
     * still shows from the old site (its outside image cards, at any depth,
     * and its outside featured image), with the attachments they came
     * from; none when it shows no such picture. Keys are matched as the swap
     * matches them, by the file below the uploads folder.
     *
     * @param  list<string>  $keys
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $fields
     *
     * @return list<string>
     */
    public static function showing(array $keys, array $content, array $fields, ConversionSite $site): array
    {
        $shown = [];
        $addresses = self::addresses($content);
        $featured = $fields[BuiltInFields::FEATURED_IMAGE] ?? null;

        if (is_array($featured) && ($featured['id'] ?? null) === null && is_string($featured['source'] ?? null)) {
            $addresses[] = $featured['source'];
        }

        foreach ($addresses as $address) {
            $key = $site->uploadsKey($address);

            if ($key !== null) {
                $shown['f:'.ImageTrace::original($key)] = true;
            }
        }

        $files = array_values(array_filter($keys, fn (string $key): bool => isset($shown[$key])));

        return $files === [] ? [] : array_values(array_filter($keys, fn (string $key): bool => str_starts_with($key, 'a:') || in_array($key, $files, true)));
    }

    /**
     * The old site's addresses of the outside image cards, at any depth.
     *
     * @param  list<array<string, mixed>>  $content
     *
     * @return list<string>
     */
    private static function addresses(array $content): array
    {
        $found = [];

        foreach ($content as $node) {
            $source = ($node['type'] ?? null) === 'image' && is_array($node['attrs'] ?? null) ? ImageCard::source($node['attrs']) : null;

            if ($source !== null) {
                $found[] = $source;
            }

            if (is_array($node['content'] ?? null)) {
                array_push($found, ...self::addresses(array_values(array_filter($node['content'], is_array(...)))));
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $node
     *
     * @return array<string, mixed>
     */
    private static function node(array $node, ConversionSite $site, MediaTargets $media): array
    {
        if (($node['type'] ?? null) === 'image' && is_array($node['attrs'] ?? null)) {
            $source = ImageCard::source($node['attrs']);
            $key = $source === null ? null : $site->uploadsKey($source);
            $found = $key === null ? null : ImageTrace::lookup($key, $media);

            if ($found !== null) {
                $node['attrs'] = [...$node['attrs'], 'mediaId' => $found, 'source' => null, 'sourceWidth' => null, 'sourceHeight' => null];
            }

            return $node;
        }

        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_map(fn (mixed $child): mixed => is_array($child) ? self::node($child, $site, $media) : $child, $node['content']);
        }

        return $node;
    }
}
