<?php

declare(strict_types=1);

namespace App\Domain\Users\Queries;

use App\Models\User;

/**
 * Whether the site still waits for its first account (issue 246).
 */
final readonly class FirstRun
{
    /**
     * True while no account exists at all, an invited one included, so the
     * first-run page is open; false from the first account on, for good.
     *
     * @phpstan-impure
     */
    public function needed(): bool
    {
        return ! User::query()->exists();
    }
}
