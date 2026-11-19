<?php

/**
 * The two image settings of Teme's service, named as the platform holds them: the one every part but the updater runs, and the updater's own.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

enum ImageSetting: string
{
    case Site = 'TEME_IMAGE';
    case Updater = 'TEME_UPDATER_IMAGE';
}
