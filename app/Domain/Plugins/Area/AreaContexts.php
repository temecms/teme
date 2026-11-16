<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use Teme\Contracts\Admin\AreaContext;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Builds what a plugin's area is told about the person it answers: who
 * they are, the admin's language and the site's time zone.
 */
final class AreaContexts
{
    /**
     * The context for this person, now.
     */
    public static function for(string $personId): AreaContext
    {
        return new AreaContext($personId, app()->getLocale(), app(SettingsReader::class)->string('general', 'timezone'));
    }
}
