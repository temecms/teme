<?php

/**
 * An export file's attachments in the companion's `media` shape: the
 * address to download from, the stored file, and its metadata with every
 * size. A file has no media type, so `mime` stays empty.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\File;

final class MediaRows
{
    /**
     * Returns the payload of one attachment item; its metadata and backup sizes are read as SerializedMeta reads them.
     *
     * @param  array{fields: array<string, string>, taxonomies: list<array{domain: string, nicename: string, name: string}>, meta: list<array{key: string, value: string}>, comments: list<array{fields: array<string, string>, meta: list<array{key: string, value: string}>}>}  $item
     * @param  array<string, list<string>>  $meta  by key, every value
     * @param  array<string, array<mixed>|null>  $serialized  the read values, by key
     *
     * @return array<string, mixed>
     */
    public static function payload(array $item, array $meta, array $serialized, FileLookups $lookups): array
    {
        $f = $item['fields'];
        $url = trim($f['wp:attachment_url'] ?? '');
        $metadata = $serialized['_wp_attachment_metadata'] ?? null;
        $backup = $serialized['_wp_attachment_backup_sizes'] ?? null;
        $original = is_array($metadata) && is_string($metadata['original_image'] ?? null) && $metadata['original_image'] !== '' ? $metadata['original_image'] : null;

        return [
            'id' => (int) ($f['wp:post_id'] ?? 0),
            'parent' => (int) ($f['wp:post_parent'] ?? 0),
            'author' => $lookups->author(trim($f['dc:creator'] ?? '')),
            'date_gmt' => ContentRows::date($f['wp:post_date_gmt'] ?? null),
            'modified_gmt' => ContentRows::date($f['wp:post_modified_gmt'] ?? null),
            'status' => trim($f['wp:status'] ?? ''),
            'title' => $f['title'] ?? '',
            'caption' => $f['excerpt:encoded'] ?? '',
            'description' => $f['content:encoded'] ?? '',
            'alt' => ContentRows::first($meta, '_wp_attachment_image_alt') ?? '',
            'mime' => null,
            'file' => ContentRows::first($meta, '_wp_attached_file') ?? '',
            'url' => $url !== '' ? $url : null,
            'original_url' => $url === '' || ! is_array($metadata) ? null : ($original === null ? $url : substr($url, 0, (int) strrpos($url, '/') + 1).$original),
            'attachment_page' => trim($f['link'] ?? ''),
            'metadata' => is_array($metadata) ? self::metadata($metadata) : null,
            'backup_sizes' => $backup,
        ];
    }

    /**
     * The parts of attachment metadata the companion sends, without the image's EXIF.
     *
     * @param  array<mixed>  $metadata
     *
     * @return array<string, mixed>
     */
    private static function metadata(array $metadata): array
    {
        $sizes = [];

        foreach (is_array($metadata['sizes'] ?? null) ? $metadata['sizes'] : [] as $name => $size) {
            if (is_array($size)) {
                $sizes[(string) $name] = [
                    'file' => self::text($size['file'] ?? ''),
                    'width' => self::number($size['width'] ?? 0),
                    'height' => self::number($size['height'] ?? 0),
                    'mime' => self::text($size['mime-type'] ?? ''),
                ];
            }
        }

        return [
            'width' => isset($metadata['width']) ? self::number($metadata['width']) : null,
            'height' => isset($metadata['height']) ? self::number($metadata['height']) : null,
            'file' => self::text($metadata['file'] ?? ''),
            'filesize' => isset($metadata['filesize']) ? self::number($metadata['filesize']) : null,
            'original_image' => isset($metadata['original_image']) ? self::text($metadata['original_image']) : null,
            'sizes' => $sizes,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
