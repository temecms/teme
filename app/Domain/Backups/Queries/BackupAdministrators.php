<?php

/**
 * Everyone the backups' alerts go to (I-2409): holders of backups.manage,
 * verified and not suspended, read when asked. No one can opt out.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final readonly class BackupAdministrators
{
    /**
     * Returns the people an alert goes to, by ID.
     *
     * @return Collection<int, User>
     */
    public function all(): Collection
    {
        return User::permission('backups.manage')
            ->whereNotNull('email_verified_at')
            ->whereNull('suspended_at')
            ->orderBy('id')
            ->get();
    }
}
