<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

/**
 * The two image settings of Teme's service, named as the platform holds them: the one every part but the updater runs, and the updater's own.
 */
enum ImageSetting: string
{
    case Site = 'TEME_IMAGE';
    case Updater = 'TEME_UPDATER_IMAGE';
}
