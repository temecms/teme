<?php

/**
 * Which images a published item shows without alt text: not marked
 * decorative, no override for this use, and no default on the item
 * (docs/architecture/media.md, "Alt text").
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use App\Domain\Media\MediaProjection;
use Illuminate\Support\Facades\DB;

final class MissingAltText
{
    /**
     * The titles of the images in the item's published revision that have no
     * alt text for this use.
     *
     * @return list<string>
     */
    public function inPublished(string $contentId): array
    {
        $content = DB::table('contents')->where('id', $contentId)->first(['type', 'published_revision_id']);

        if ($content === null || $content->published_revision_id === null) {
            return [];
        }

        $fields = (array) json_decode((string) DB::table('content_revisions')->where('id', $content->published_revision_id)->value('fields'), true);
        $missing = [];

        foreach (MediaProjection::refsFor((string) $content->type) as $key) {
            $use = $fields[$key] ?? null;

            if (! is_array($use) || ($use['decorative'] ?? false) || trim((string) ($use['alt'] ?? '')) !== '') {
                continue;
            }

            $item = DB::table('media')->where('id', $use['id'] ?? '')->first(['title', 'alt']);

            if ($item !== null && trim((string) $item->alt) === '') {
                $missing[] = (string) $item->title;
            }
        }

        return $missing;
    }
}
