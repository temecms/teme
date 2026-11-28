<?php

/**
 * Whether an image is the site's design.site_icon, which earns it the
 * favicon sizes (issue 388), as ShareImageUse does for share images.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Media\Queries;

use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

final class SiteIconUse
{
    /** The design setting that chooses the site icon. */
    public const string KEY = 'site_icon';

    /** The design settings group. */
    public const string GROUP = 'design';

    /**
     * Whether this image is the live site icon.
     */
    public static function wanted(string $mediaId): bool
    {
        return self::chosen() === $mediaId;
    }

    /**
     * The live site icon's media ID, or null when none is chosen.
     */
    public static function chosen(): ?string
    {
        $id = app(SettingsReader::class)->string(self::GROUP, self::KEY);

        return $id === '' ? null : $id;
    }

    /**
     * Up to $limit images after $lastId that are the site icon and lack
     * icon-32, for teme:media:regenerate.
     *
     * @return list<string>
     */
    public static function lacking(?string $lastId, int $limit): array
    {
        $chosen = self::chosen();

        if ($chosen === null) {
            return [];
        }

        return DB::table('media')
            ->where('id', $chosen)
            ->where('kind', 'image')
            ->whereNull('deleted_at')
            ->whereNull('variants->icon-32')
            ->when($lastId !== null, fn ($query) => $query->where('id', '>', $lastId))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }
}
