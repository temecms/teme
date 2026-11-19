<?php

/**
 * The site's own fediverse work, as a token (docs/plans/fediverse-publishing.md,
 * follow-up R7): what QueueActivity accepts in place of a person when core's
 * content listener asks, so no one runs with the operator's power and the
 * plugins' listeners it calls act for nobody. Only ContentActivities issues
 * one; a token it did not issue is refused.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

use LogicException;
use WeakMap;

final class SiteWork
{
    /** Where the container keeps the tokens issued in this process. */
    private const string ISSUED = 'fediverse.site-work';

    private function __construct() {}

    /**
     * Whether this token was issued by core's content listener.
     */
    public static function issued(?self $token): bool
    {
        if ($token === null || ! app()->bound(self::ISSUED)) {
            return false;
        }

        $issued = app(self::ISSUED);

        return $issued instanceof WeakMap && isset($issued[$token]);
    }

    /**
     * A fresh token, recorded as issued, for ContentActivities alone.
     *
     * @throws LogicException when anything else asks
     */
    public static function issue(): self
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? null;

        if ($caller !== ContentActivities::class) {
            throw new LogicException('Only core’s content listener issues the site’s own work.');
        }

        $issued = app()->bound(self::ISSUED) ? app(self::ISSUED) : null;

        if (! $issued instanceof WeakMap) {
            $issued = new WeakMap;
            app()->instance(self::ISSUED, $issued);
        }

        $token = new self;
        $issued[$token] = true;

        return $token;
    }
}
