<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Models\Content;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Site\ViewModels\ImageRef;
use Illuminate\Support\Facades\DB;

/**
 * An article's lead image, the one it loads first (I-245), decided exactly:
 * its featured image when it has one and the theme shows it; otherwise an
 * image card placed before any paragraph, heading or list with text;
 * otherwise none. An image in the trash is never the lead.
 */
final class LeadImage
{
    /** The node types that end the search for an opening image card, once they hold text. */
    private const array TEXT_NODES = ['paragraph', 'heading', 'bulletList', 'orderedList', 'taskList', 'blockquote'];

    /** The image card's name (ImageCard::name()). */
    private const string IMAGE_CARD = 'image';

    /**
     * The lead, and the ID of the image card it comes from (null for the featured image).
     *
     * @param  array<mixed>  $document
     * @param  array<string, mixed>|null  $fields  the revision's fields, when not the published ones on $content
     *
     * @return array{ref: ImageRef|null, nodeId: string|null}
     */
    public function for(Content $content, array $document, bool $themeShowsFeatured, ?array $fields = null): array
    {
        $fields ??= (array) $content->fields;
        $featured = $fields[BuiltInFields::FEATURED_IMAGE] ?? null;

        if ($themeShowsFeatured && is_array($featured) && is_string($featured['id'] ?? null)) {
            $media = self::live($featured['id']);

            if ($media !== null) {
                return ['ref' => new ImageRef($featured['id'], self::text($featured['alt'] ?? null), (bool) ($featured['decorative'] ?? false), self::text($media->caption), self::text($media->credit)), 'nodeId' => null];
            }
        }

        foreach ((array) ($document['content'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === self::IMAGE_CARD) {
                $attrs = (array) ($node['attrs'] ?? []);
                $id = (string) ($attrs['mediaId'] ?? '');

                return self::live($id) === null
                    ? ['ref' => null, 'nodeId' => null]
                    : ['ref' => new ImageRef($id, self::text($attrs['alt'] ?? null), (bool) ($attrs['decorative'] ?? false), self::text($attrs['caption'] ?? null), self::text($attrs['credit'] ?? null)), 'nodeId' => (string) ($attrs['id'] ?? '')];
            }

            if (in_array($node['type'] ?? null, self::TEXT_NODES, true) && self::hasText($node)) {
                break;
            }
        }

        return ['ref' => null, 'nodeId' => null];
    }

    /**
     * The media item's caption and credit while it is live, or null when it is missing or in the trash.
     */
    private static function live(string $mediaId): ?object
    {
        if ($mediaId === '') {
            return null;
        }

        app(RenderRecord::class)->tag(PageTags::media($mediaId));

        return DB::table('media')->where('id', $mediaId)->whereNull('deleted_at')->first(['caption', 'credit']);
    }

    /**
     * Whether the node holds any text, however deep.
     *
     * @param  array<mixed>  $node
     */
    private static function hasText(array $node): bool
    {
        if (is_string($node['text'] ?? null) && trim($node['text']) !== '') {
            return true;
        }

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child) && self::hasText($child)) {
                return true;
            }
        }

        return false;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
