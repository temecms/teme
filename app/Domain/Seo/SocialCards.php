<?php

/**
 * Search and social's section "Social cards" (I-293): the image a card shows
 * when what is shared has none of its own. Read through the settings, so a
 * page that falls back to it carries the group's tag.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Seo;

use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;

final class SocialCards implements SearchAndSocialSection
{
    public const string DEFAULT_IMAGE = 'default_social_image';

    public function order(): int
    {
        return 400;
    }

    public function key(): string
    {
        return 'social-cards';
    }

    public function label(): string
    {
        return 'Social cards';
    }

    public function settings(): array
    {
        return [
            Setting::media(self::DEFAULT_IMAGE, 'Default social image', 'On the card when something is shared that has no image of its own: no social image, no featured image and none in its body. Archives and the home page use it too. 1200 × 630 pixels suits most services.', '0.1.0'),
        ];
    }

    /**
     * The default social image's media ID, or null when none is chosen.
     */
    public static function defaultImage(): ?string
    {
        $id = app(SettingsReader::class)->string(SearchAndSocial::KEY, self::DEFAULT_IMAGE);

        return $id === '' ? null : $id;
    }
}
