<?php

/**
 * Who is looking at a plugin's area, in which language and time zone, and
 * which of the plugin's own permissions they hold, and the statistics
 * period when the page asks for one.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Admin;

/**
 * Who is looking at a plugin's area, in which language and time zone, and
 * which of the plugin's own permissions they hold, and the statistics
 * period when the page asks for one.
 *
 * @since 1.0.0
 */
final readonly class AreaContext
{
    /** @since 1.0.0 */
    public function __construct(
        public string $personId,
        public string $locale,
        public string $timezone,
        /**
         * The ids of this plugin's own permissions the person holds, such as
         * `view-all`; never another plugin's.
         *
         * @var list<string>
         *
         * @since 1.0.0
         */
        public array $permissions = [],
        /**
         * The statistics window for figures and charts; null when the page
         * has none (a Home panel keeps the default without a select).
         *
         * @since next
         */
        public ?AreaPeriod $period = null,
    ) {}
}
