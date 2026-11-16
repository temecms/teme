<?php

declare(strict_types=1);

namespace Teme\Contracts\Privacy;

/**
 * One page of a holder's answer to an export request: the groups it found,
 * and whether it has given the last of them. A preview until Teme 1.0.
 *
 * @since 0.5.0
 */
final readonly class ExportPage
{
    /**
     * @param  list<ExportGroup>  $groups  what this page found, in the order the person reads it
     * @param  bool  $done  true once this page holds the last of it
     *
     * @since 0.5.0
     */
    public function __construct(
        public array $groups,
        public bool $done,
    ) {}
}
