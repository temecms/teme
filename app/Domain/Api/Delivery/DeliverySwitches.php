<?php

declare(strict_types=1);

namespace App\Domain\Api\Delivery;

use App\Domain\Seo\SearchAndSocial;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Whether the site shares its published content as data: the API's switch
 * and llms.txt's, both off until an administrator ticks them (I-713).
 * Read through the settings, so every answer that asks carries the seo tag
 * and ticking a box purges it at once.
 */
final class DeliverySwitches
{
    /** The API's switch, in Search and social's row. */
    public const string API = 'api_enabled';

    /** llms.txt's and the .md pages' switch, in Search and social's row. */
    public const string LLMS = 'llms_txt';

    /**
     * Whether /api/v1 answers.
     */
    public static function apiOn(): bool
    {
        return app(SettingsReader::class)->bool(SearchAndSocial::KEY, self::API);
    }

    /**
     * Whether /llms.txt and the .md pages answer.
     */
    public static function llmsOn(): bool
    {
        return app(SettingsReader::class)->bool(SearchAndSocial::KEY, self::LLMS);
    }
}
