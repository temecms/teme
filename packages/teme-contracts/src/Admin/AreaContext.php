<?php

declare(strict_types=1);

namespace Teme\Contracts\Admin;

/**
 * Who is looking at a plugin's area, in which language and time zone.
 *
 * @since 0.9.0
 */
final readonly class AreaContext
{
    /** @since 0.9.0 */
    public function __construct(
        public string $personId,
        public string $locale,
        public string $timezone,
    ) {}
}
