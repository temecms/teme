<?php

/**
 * What an import run sets out to do: bring a whole site across, catch up
 * on what changed since the last finished run (I-345), or bring the media
 * files of an import that brought its articles first (#716).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import;

enum ImportRunKind: string
{
    case Full = 'full';
    case CatchUp = 'catch-up';

    /**
     * A picture pass: the media files of the run it follows, brought after
     * that run's articles, each swapped into the posts that use it.
     *
     * @since 1.5.0
     */
    case MediaPass = 'media';

    /**
     * The words people read for this kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full import',
            self::CatchUp => 'Catch-up',
            self::MediaPass => 'Pictures',
        };
    }
}
