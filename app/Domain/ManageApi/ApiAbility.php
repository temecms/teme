<?php

/**
 * What a management API key may be used for, chosen when it is made from
 * this fixed list (docs/proposals/management-api.md). A request needs the
 * route's abilities and its person's permission through the Operation.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\ManageApi;

enum ApiAbility: string
{
    case SiteRead = 'site.read';
    case MediaWrite = 'media.write';
    case ContentWrite = 'content.write';
    case ContentPublish = 'content.publish';

    /**
     * Every ability's value, in the list's order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $ability): string => $ability->value, self::cases());
    }

    /**
     * The words the settings page shows for it.
     */
    public function label(): string
    {
        return match ($this) {
            self::SiteRead => __('Read the site: who the key is, terms and people'),
            self::MediaWrite => __('Upload media and change its details'),
            self::ContentWrite => __('Create drafts'),
            self::ContentPublish => __('Publish and schedule'),
        };
    }
}
