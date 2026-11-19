<?php

/**
 * Ends every session a person has: remember-me cookies stop working, and with
 * the database session driver their open sessions are deleted. Called from
 * inside Operations. A production site always uses the database driver: the
 * start rules refuse any other (docs/architecture/security.md).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountSessions
{
    public static function endAllFor(User $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
