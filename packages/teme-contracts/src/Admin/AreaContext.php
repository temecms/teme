<?php

/**
 * Who is looking at a plugin's area, in which language and time zone, and
 * which of the plugin's own permissions they hold.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Admin;

/**
 * Who is looking at a plugin's area, in which language and time zone, and
 * which of the plugin's own permissions they hold.
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
    ) {}
}
