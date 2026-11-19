<?php

/**
 * What an invitation offers, for its own page: who it is for, the role it
 * gives, who sent it and the site's name. Read only for a link that still
 * works, so a guessed address learns nothing.
 *
 * @package Teme
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Queries;

use App\Domain\Security\SecurityEventType;
use App\Domain\Users\Roles;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

final class InvitationSummary
{
    public function __construct(private SettingsReader $settings) {}

    /**
     * The invitation behind an address and its token: the invited person's
     * name, the role's label, the first sender's name (null when the
     * operator sent it or that account is gone) and the site's name (null
     * when it has none); null when the link no longer works.
     *
     * @since 1.2.0
     *
     * @return array{name: string, role: string|null, invitedBy: string|null, site: string|null}|null
     */
    public function for(string $email, string $token): ?array
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->first();

        if ($user === null || $user->invited_at === null || $token === '' || ! PasswordBroker::broker('invitations')->tokenExists($user, $token)) {
            return null;
        }

        $role = $user->getRoleNames()->first();

        return [
            'name' => (string) $user->name,
            'role' => $role === null ? null : __(Roles::LABELS[$role] ?? $role),
            'invitedBy' => $this->sender($user->id),
            'site' => $this->siteName(),
        ];
    }

    /** The name of whoever first sent the invitation, while their account stands. */
    private function sender(int $userId): ?string
    {
        $by = DB::table('security_events')
            ->where('type', SecurityEventType::UserInvited->value)
            ->where('user_id', $userId)
            ->whereRaw("context->>'resent' IS NULL")
            ->orderBy('first_at')
            ->orderBy('id')
            ->selectRaw("context->>'by' as sender")
            ->value('sender');

        if (! is_string($by) || ! ctype_digit($by)) {
            return null;
        }

        $name = User::query()->whereKey((int) $by)->value('name');

        return is_string($name) ? $name : null;
    }

    /** The site's title, or null when it has none. */
    private function siteName(): ?string
    {
        try {
            $name = trim($this->settings->string('general', 'site_title'));
        } catch (Throwable) {
            return null;
        }

        return $name === '' ? null : $name;
    }
}
