<?php

declare(strict_types=1);

namespace App\Domain\Status;

/**
 * Something about the site an administrator must know about until it is
 * fixed (I-221): a check that answers with a banner while the problem
 * lasts, shown only to people who hold the ability to fix it.
 */
interface StatusCheck
{
    /**
     * Who may see the banner: the ability that lets them fix the problem.
     */
    public function ability(): string;

    /**
     * The banner while the problem lasts, or null when all is well.
     */
    public function banner(): ?StatusBanner;
}
