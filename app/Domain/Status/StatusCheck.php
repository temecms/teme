<?php

/**
 * Something about the site an administrator must know about until it is
 * fixed (I-221): a check that answers with a banner while the problem
 * lasts, shown only to people who hold the ability to fix it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Status;

interface StatusCheck
{
    /**
     * A stable key for the check, not its translated label, so Home's
     * summary rows can tell which checks they own.
     *
     * @since next
     */
    public function key(): string;

    /**
     * Who may see the banner: the ability that lets them fix the problem.
     */
    public function ability(): string;

    /**
     * What the check is about, in a few words, as the site status row's label.
     *
     * @since 0.8.0-alpha.1
     */
    public function label(): string;

    /**
     * The banner while the problem lasts, or null when all is well.
     */
    public function banner(): ?StatusBanner;
}
