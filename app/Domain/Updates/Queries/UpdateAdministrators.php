<?php

declare(strict_types=1);

namespace App\Domain\Updates\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Everyone the updates' emails go to (I-3326): holders of updates.manage, verified and not suspended, read when asked. No one can opt out.
 */
final readonly class UpdateAdministrators
{
    /**
     * Returns the people an update's email goes to, by ID.
     *
     * @return Collection<int, User>
     */
    public function all(): Collection
    {
        return User::permission('updates.manage')
            ->whereNotNull('email_verified_at')
            ->whereNull('suspended_at')
            ->orderBy('id')
            ->get();
    }
}
