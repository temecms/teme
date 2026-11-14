<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Models\User;

/**
 * A menu entry's count of what waits for the person's action (I-220): asked
 * once a request, for the entries the person may open. Actionable counts
 * only; 0 draws no badge. Phase 5 opens it to plugins through a contract.
 */
interface BadgeProvider
{
    /**
     * How many things wait for this person's action.
     */
    public function countFor(User $user): int;
}
