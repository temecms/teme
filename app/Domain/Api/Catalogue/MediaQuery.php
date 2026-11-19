<?php

/**
 * Reads a media file for the API (D26, D40): only while a live, public,
 * unprotected item that shows it through the API does.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api\Catalogue;

use App\Domain\Api\FieldList;
use App\Domain\Api\Live;
use App\Domain\Api\Shapes\MediaShape;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardMedia;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Types\MediaField;
use Illuminate\Support\Facades\DB;

final class MediaQuery
{
    /**
     * The media file with this ID while a live, public, unprotected item shows
     * it through the API (D26): in its body, as its featured image, or in a
     * field the site shows; null otherwise, as for a file that doesn't exist.
     * Tags the file and each item that shows it.
     *
     * @return array<string, mixed>|null the file as MediaShape gives it
     */
    public function shown(string $id): ?array
    {
        app(RenderRecord::class)->tag(PageTags::media($id));

        $uses = Live::publicOnly(Live::apply(DB::table('media_usages')->join('contents', 'contents.id', '=', 'media_usages.content_id')))
            ->where('media_usages.media_id', $id)
            ->where('media_usages.scope', 'published')
            ->get(['contents.id', 'contents.type', 'media_usages.ref']);

        $shownBy = [];

        foreach ($uses as $use) {
            if ($this->shows((string) $use->type, (string) $use->ref)) {
                $shownBy[(string) $use->id] = true;
            }
        }

        if ($shownBy === []) {
            return null;
        }

        app(RenderRecord::class)->tag(...array_map(fn (string $item): string => PageTags::content($item), array_keys($shownBy)));
        $media = app(CardMedia::class)->forIds([$id])[$id] ?? null;

        return $media === null ? null : MediaShape::of([...$media, 'id' => $id]);
    }

    /**
     * Whether a use of this ref is one the API shows: the body's (an image or
     * other card, or a link to the file), the featured image, or a media
     * field the site shows.
     */
    private function shows(string $type, string $ref): bool
    {
        if (str_starts_with($ref, 'node:') || str_starts_with($ref, 'link:') || $ref === 'field:'.BuiltInFields::FEATURED_IMAGE) {
            return true;
        }

        foreach (FieldList::shown($type) as $field) {
            if ($field->type instanceof MediaField && $ref === 'field:'.$field->key) {
                return true;
            }
        }

        return false;
    }
}
