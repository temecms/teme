<?php

declare(strict_types=1);

namespace Teme\Contracts\Privacy;

/**
 * One page of a holder's answer to an erase request: how much it removed,
 * how much it kept and why, and whether it is finished.
 * A preview until Teme 1.0 (I-3187).
 *
 * @since 0.5.0
 */
final readonly class ErasePage
{
    /**
     * @param  int  $removed  items erased or anonymised on this page
     * @param  int  $retained  items kept on this page
     * @param  list<string>  $reasons  why anything was kept, one whole sentence each
     * @param  bool  $done  true once nothing is left to erase or keep
     *
     * @since 0.5.0
     */
    public function __construct(
        public int $removed,
        public int $retained,
        public array $reasons,
        public bool $done,
    ) {}
}
