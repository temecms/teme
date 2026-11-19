<?php

/**
 * The media a document's cards show, in one query: the lookup the server's
 * renderer and the browser's editor share, so both halves draw a card from
 * the same facts, a sound or video file's own address among them. URLs are
 * worked out here and never stored (rule 9).
 * Scoped to a request or a job (CardServiceProvider): what it has read, and
 * what it found missing, it keeps until then, so a listing reads its cards'
 * images once and each card draws its own from memory (issue 30).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Media\MediaDisks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CardMedia
{
    /**
     * Each ID this request or job has asked about: its item, or null when
     * it is missing or in the trash.
     *
     * @var array<string, array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int, kind: string, fileUrl: string|null, mimeType: string, uploadedAt: string}|null>
     */
    private array $known = [];

    /**
     * Every live media item among the IDs, keyed by ID: its full variants map
     * with each variant's URL added, its size, alt text, title and focal
     * point, its kind, media type and upload time, and the file's own public
     * address for anything but an image. A trashed or missing item is left
     * out, and a card naming it renders as nothing. Only IDs this request or job hasn't asked about
     * are read, all in one statement.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int, kind: string, fileUrl: string|null, mimeType: string, uploadedAt: string}>
     */
    public function forIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return [];
        }

        // Each image asked for, live or not, read now or remembered: a
        // trashed one draws as nothing, and the page changes when it comes
        // back (D2).
        app(RenderRecord::class)->tag(...array_map(PageTags::media(...), $ids));

        $this->read(array_values(array_filter($ids, fn (string $id): bool => ! array_key_exists($id, $this->known))));

        $media = [];

        foreach ($ids as $id) {
            $item = $this->known[$id] ?? null;

            if ($item !== null) {
                $media[$id] = $item;
            }
        }

        return $media;
    }

    /**
     * The media the document's cards name, as forIds() returns it.
     *
     * @param  array<mixed>  $document
     *
     * @return array<string, array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int, kind: string, fileUrl: string|null, mimeType: string, uploadedAt: string}>
     */
    public function forDocument(array $document): array
    {
        $ids = [];

        foreach (References::of($document) as $reference) {
            if ($reference->kind === 'media') {
                $ids[] = $reference->id;
            }
        }

        return $this->forIds($ids);
    }

    /**
     * Reads these items in one statement and remembers each, one missing
     * or in the trash as null, so it isn't asked for again.
     *
     * @param  list<string>  $ids
     */
    private function read(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($ids as $id) {
            $this->known[$id] = null;
        }

        $disk = MediaDisks::public();

        foreach (DB::table('media')->whereIn('id', $ids)->whereNull('deleted_at')
            ->get(['id', 'kind', 'mime_type', 'original_path', 'title', 'alt', 'width', 'height', 'variants', 'focal_x', 'focal_y', 'created_at']) as $item) {
            $variants = [];

            foreach ((array) json_decode((string) $item->variants, true) as $key => $variant) {
                $variants[(string) $key] = [
                    'path' => (string) $variant['path'],
                    'url' => $disk->url((string) $variant['path']),
                    'width' => (int) $variant['width'],
                    'height' => (int) $variant['height'],
                    'bytes' => (int) ($variant['bytes'] ?? 0),
                ];
            }

            $this->known[(string) $item->id] = [
                'variants' => $variants,
                'width' => (int) $item->width,
                'height' => (int) $item->height,
                'alt' => $item->alt === null ? null : (string) $item->alt,
                'title' => (string) $item->title,
                'focalX' => (int) $item->focal_x,
                'focalY' => (int) $item->focal_y,
                'kind' => (string) $item->kind,
                // An image's original is private; only its variants are public (docs/architecture/media.md).
                'fileUrl' => $item->kind === 'image' ? null : $disk->url((string) $item->original_path),
                'mimeType' => (string) $item->mime_type,
                'uploadedAt' => CarbonImmutable::parse((string) $item->created_at)->utc()->format(DATE_ATOM),
            ];
        }
    }
}
