<?php

/**
 * What validating a staged release found: reasons to refuse it, what waits
 * for the administrator's agreement, and warnings the report keeps.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Installer;

final readonly class StageOutcome
{
    /**
     * @param  list<string>  $refusals
     * @param  array<string, mixed>  $needs
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $refusals = [],
        public array $needs = [],
        public array $warnings = [],
    ) {}

    /** Whether the release is refused. */
    public function refused(): bool
    {
        return $this->refusals !== [];
    }

    /**
     * Whether every need is covered by what the administrator agreed to.
     *
     * @param  array<string, mixed>  $agreed
     */
    public function coveredBy(array $agreed): bool
    {
        foreach ($this->needs as $key => $need) {
            if (! array_key_exists($key, $agreed) || $agreed[$key] != $need) {
                return false;
            }
        }

        return true;
    }
}
