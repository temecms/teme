<?php

/**
 * Whether an image is used as a featured or social image, or is the site's
 * default social image, which is what earns it the share images (I-855). Items'
 * uses are read from media_usages, which records every revision's media fields
 * as field:{key}.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use App\Domain\Fields\BuiltInFields;
use App\Domain\Seo\SocialCards;
use Illuminate\Support\Facades\DB;

final class ShareImageUse
{
    /**
     * The usage refs that earn an image its share images.
     *
     * @return list<string>
     */
    public static function refs(): array
    {
        return ['field:'.BuiltInFields::FEATURED_IMAGE, 'field:'.BuiltInFields::SOCIAL_IMAGE];
    }

    /**
     * Whether any item's working or published revision uses the image so, or
     * it is the site's default social image.
     */
    public static function wanted(string $mediaId): bool
    {
        return SocialCards::defaultImage() === $mediaId
            || DB::table('media_usages')->where('media_id', $mediaId)->whereIn('ref', self::refs())->exists();
    }

    /**
     * Whether this item uses the image so.
     */
    public static function usedBy(string $mediaId, string $contentId): bool
    {
        return DB::table('media_usages')->where('media_id', $mediaId)->where('content_id', $contentId)->whereIn('ref', self::refs())->exists();
    }

    /**
     * Up to $limit images after $lastId, in ID order, that are wanted and
     * have no share images yet (the site's default social image counted), for teme:media:regenerate to page through.
     *
     * @return list<string>
     */
    public static function lacking(?string $lastId, int $limit): array
    {
        $default = SocialCards::defaultImage();

        return DB::table('media')
            ->where('kind', 'image')
            ->whereNull('deleted_at')
            ->whereNull('variants->social')
            ->where(fn ($wanted) => $wanted
                ->whereExists(fn ($uses) => $uses->select(DB::raw(1))->from('media_usages')->whereColumn('media_usages.media_id', 'media.id')->whereIn('media_usages.ref', self::refs()))
                ->when($default !== null, fn ($query) => $query->orWhere('media.id', $default)))
            ->when($lastId !== null, fn ($query) => $query->where('id', '>', $lastId))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }
}
