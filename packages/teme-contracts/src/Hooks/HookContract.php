<?php

/**
 * The rules core declares on a hook's class: what a plugin must have been
 * granted to listen, what a listener's failure does, and, for a hook being
 * retired, when it stops being sent and what replaces it.
 *
 * @package Teme
 * @since   0.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

use Attribute;

/**
 * The rules core declares on a hook's class: what a plugin must have been
 * granted to listen, what a listener's failure does, and, for a hook being
 * retired, when it stops being sent and what replaces it. The version a
 * hook appeared in is its class docblock's `@since`.
 *
 * @since 0.5.0
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class HookContract
{
    /**
     * @param  string|null  $capability  what a plugin must have been granted to listen; null for none
     * @param  Failure  $failure  what a listener's failure does: honoured by Transform and Collect only;
     *                            a Notification goes on, a Gate denies and a Resolve answers nothing, always
     * @param  string|null  $deprecated  the version that deprecated it
     * @param  string|null  $removedIn  the version that stops sending it: at least one minor after $deprecated
     * @param  class-string<Hook>|null  $use  its replacement
     *
     * @since 0.5.0
     */
    public function __construct(
        public ?string $capability = null,
        public Failure $failure = Failure::SkipListener,
        public ?string $deprecated = null,
        public ?string $removedIn = null,
        public ?string $use = null,
    ) {}
}
