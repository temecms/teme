<?php

/**
 * Builds a hook's plain values from core's own (I-3353): never a model, a
 * collection or a framework date, which would tie every plugin to
 * Laravel's version (rule 5).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Hooks;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Visibility;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\Models\Media;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Site\Head\RobotsMeta;
use App\Domain\Site\SiteLanguage;
use App\Domain\Taxonomy\Models\Term;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Media\MediaRef;
use Teme\Contracts\Taxonomy\TermRef;

final class Payloads
{
    /**
     * Returns the item as hooks tell of it, with its live address read now,
     * its language as the public page's `lang` says it (I-3360), and whether
     * search engines may list that page.
     */
    public static function item(Content $content): ItemRef
    {
        $url = Addresses::urlFor($content);

        return new ItemRef(
            (string) $content->id,
            (string) $content->type,
            (string) $content->title,
            (string) $content->status,
            $url,
            app(SiteLanguage::class)->forContent($content),
            indexable: $url !== null && self::indexable($content),
            submission: $content->submitted_via !== null,
        );
    }

    /**
     * Whether the item's page lets search engines list it, asked of the
     * robots meta the public head writes, with what the page draws it from:
     * its published revision's fields, its type's row (never the home
     * page's), and core's password form for a protected item.
     */
    private static function indexable(Content $content): bool
    {
        $fields = $content->published_revision_id === null ? [] : ContentRevision::query()->whereKey($content->published_revision_id)->value('fields');
        $fields = is_array($fields) ? $fields : [];
        $hidden = ($fields[BuiltInFields::NOINDEX] ?? false) === true || (TitlesAndListing::hidesType((string) $content->type) && ! self::isFront($content));
        $kind = $content->visibility === Visibility::Protected ? 'protected' : 'single';

        return ! in_array('noindex', RobotsMeta::directives($kind, $hidden, $fields), true);
    }

    /**
     * Whether the item is the home page, or one of its language versions.
     */
    private static function isFront(Content $content): bool
    {
        $addresses = AddressSettings::current();

        if ($addresses->frontShows !== AddressSettings::FRONT_PAGE || $addresses->frontPageId === '') {
            return false;
        }

        if ($addresses->frontPageId === (string) $content->id) {
            return true;
        }

        return $content->translation_group !== null
            && Content::query()->whereKey($addresses->frontPageId)->value('translation_group') === $content->translation_group;
    }

    /**
     * Returns the term as hooks tell of it.
     */
    public static function term(Term $term): TermRef
    {
        return new TermRef(
            (string) $term->id,
            (string) $term->taxonomy,
            (string) $term->name,
            (string) $term->slug,
            $term->parent_id === null ? null : (string) $term->parent_id,
            $term->language === null ? null : (string) $term->language,
        );
    }

    /**
     * Returns the media item as hooks tell of it.
     */
    public static function media(Media $media): MediaRef
    {
        return new MediaRef(
            (string) $media->id,
            $media->kind,
            $media->mime_type,
            $media->original_filename,
            $media->title,
            $media->size_bytes,
            $media->width,
            $media->height,
        );
    }

    /**
     * Returns the moment as PHP's own DateTimeImmutable, in a zone named UTC.
     */
    public static function date(CarbonInterface $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));
    }
}
