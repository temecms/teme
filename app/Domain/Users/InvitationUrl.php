<?php

/**
 * Built from APP_URL, never from a request's Host header (study G, S7).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users;

final class InvitationUrl
{
    public static function for(string $token, string $email): string
    {
        return rtrim((string) config('app.url'), '/').route('invitation.show', ['token' => $token, 'email' => $email], false);
    }
}
