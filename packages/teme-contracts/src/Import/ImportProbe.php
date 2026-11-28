<?php

/**
 * What a reader finds in a file when it probes it: the system it came
 * from, its version, its languages and how many items of each kind.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Import;

/**
 * The answer of a probe. Counts are keyed by ImportKind, and a kind the
 * file doesn't hold is left out.
 *
 * @since next
 */
final readonly class ImportProbe
{
    /**
     * @param  list<string>  $languages  the languages the site's content is in, as BCP 47 tags
     * @param  array<string, int>  $counts  how many items of each kind the file holds
     *
     * @since next
     */
    public function __construct(
        public string $system,
        public ?string $version,
        public ?string $siteAddress,
        public array $languages,
        public array $counts,
    ) {}
}
