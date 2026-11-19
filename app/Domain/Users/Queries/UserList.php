<?php

/**
 * The People screen: its views, each counted, and exactly what the screen
 * shows of the people in the one asked for. A site's staff is counted in
 * dozens, not thousands, so everyone is read at once; paging arrives if a
 * publication ever needs it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Users\Queries;

use App\Domain\Security\SecurityEventType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type PersonRow array{id: int, name: string, email: string, role: string|null, status: 'active'|'invited'|'suspended', twoFactor: bool, lastSignInAt: string|null, invitationFailed: bool, invitedAt: string|null, invitedBy: string|null, linkWorksUntil: string|null, picture: string|null}
 */
final class UserList
{
    public function __construct(private readonly PersonPictures $pictures) {}

    /**
     * Every view, in the order its tab is drawn: everyone with an account,
     * each core role as Roles::LABELS lists them, the suspended, then the
     * invitations waiting.
     *
     * @var list<string>
     */
    public const array VIEWS = ['all', 'administrator', 'editor', 'author', 'contributor', 'suspended', 'invited'];

    /** @var array<string, string> Each core role's tab, in the plural. */
    private const array ROLE_TABS = [
        'administrator' => 'Administrators',
        'editor' => 'Editors',
        'author' => 'Authors',
        'contributor' => 'Contributors',
    ];

    /**
     * The view asked for, or All when it isn't one; every view's tab with
     * its count; the people in that view, by name; and everyone with an
     * account, whatever the view, as whom a deleted person's work can go to.
     *
     * @return array{view: string, tabs: list<array{key: string, label: string, count: int}>, users: list<PersonRow>, reassignOptions: list<array{value: int, label: string}>}
     */
    public function screen(string $requested): array
    {
        $everyone = $this->everyone();
        $view = in_array($requested, self::VIEWS, true) ? $requested : 'all';
        $tabs = [];

        foreach (self::VIEWS as $key) {
            $tabs[] = ['key' => $key, 'label' => $this->label($key), 'count' => count(array_filter($everyone, fn (array $row): bool => self::shows($key, $row)))];
        }

        $accounts = array_filter($everyone, fn (array $row): bool => $row['invitedAt'] === null);

        return [
            'view' => $view,
            'tabs' => $tabs,
            'users' => array_values(array_filter($everyone, fn (array $row): bool => self::shows($view, $row))),
            'reassignOptions' => array_values(array_map(fn (array $row): array => ['value' => $row['id'], 'label' => $row['name']], $accounts)),
        ];
    }

    /**
     * Whether a view lists this person: an invitation waits under Invited
     * only; anyone else is under All, under their role's tab, and under
     * Suspended while suspended.
     *
     * @param  PersonRow  $row
     */
    private static function shows(string $view, array $row): bool
    {
        if ($row['invitedAt'] !== null) {
            return $view === 'invited';
        }

        return match ($view) {
            'all' => true,
            'suspended' => $row['status'] === 'suspended',
            'invited' => false,
            default => $row['role'] === $view,
        };
    }

    private function label(string $view): string
    {
        return __(match ($view) {
            'all' => 'All',
            'suspended' => 'Suspended',
            'invited' => 'Invited',
            default => self::ROLE_TABS[$view] ?? $view,
        });
    }

    /**
     * Everyone, by name, with only what the screen shows of them.
     *
     * @return list<PersonRow>
     */
    private function everyone(): array
    {
        $users = User::query()->with('roles:id,name')->orderBy('name')->orderBy('id')->get();
        $ids = $users->pluck('id')->all();
        $invited = $users->filter(fn (User $user): bool => $user->invited_at !== null)->values();

        $lastSignIn = $this->latest(SecurityEventType::SignInSucceeded, $ids);
        $invitationFailed = $this->latest(SecurityEventType::InvitationMailFailed, $ids);
        $invitedBy = $this->invitedBy($invited->pluck('id')->all());
        $worksUntil = $this->linksWorkUntil($invited->pluck('email')->all());
        $pictures = $this->pictures->for(array_map(intval(...), $ids));

        return $users->map(function (User $user) use ($lastSignIn, $invitationFailed, $invitedBy, $worksUntil, $pictures): array {
            $failedAt = $invitationFailed[$user->id] ?? null;
            $isInvited = $user->invited_at !== null;

            return [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
                'status' => match (true) {
                    $user->suspended_at !== null => 'suspended',
                    $isInvited => 'invited',
                    default => 'active',
                },
                'twoFactor' => $user->two_factor_confirmed_at !== null,
                'lastSignInAt' => isset($lastSignIn[$user->id]) ? Carbon::parse($lastSignIn[$user->id])->toIso8601String() : null,
                'invitationFailed' => $isInvited && $failedAt !== null && Carbon::parse($failedAt)->greaterThanOrEqualTo($user->invited_at),
                'invitedAt' => $user->invited_at?->toIso8601String(),
                'invitedBy' => $isInvited ? ($invitedBy[$user->id] ?? null) : null,
                'linkWorksUntil' => $isInvited ? ($worksUntil[$user->email] ?? null) : null,
                // An invitation shows the dashed ring, never a picture.
                'picture' => $isInvited ? null : ($pictures[(int) $user->id] ?? null),
            ];
        })->values()->all();
    }

    /**
     * Who first sent each invitation, by name, read from the security log
     * for display only (I-118): none when the operator sent it, their
     * account is gone, or the log no longer holds it.
     *
     * @param  list<int>  $ids
     *
     * @return array<int, string>
     */
    private function invitedBy(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // The first sending of each, never a resend; "by" holds an account's ID, or "operator".
        $senders = [];

        foreach (DB::table('security_events')
            ->where('type', SecurityEventType::UserInvited->value)
            ->whereIn('user_id', $ids)
            ->whereRaw("context->>'resent' IS NULL")
            ->orderBy('first_at')
            ->orderBy('id')
            ->selectRaw("user_id, context->>'by' AS sender")
            ->get() as $event) {
            $senders[(int) $event->user_id] ??= (string) $event->sender;
        }

        $names = User::query()
            ->whereIn('id', array_values(array_filter($senders, ctype_digit(...))))
            ->pluck('name', 'id')
            ->all();

        $by = [];

        foreach ($senders as $userId => $sender) {
            if (isset($names[(int) $sender])) {
                $by[$userId] = (string) $names[(int) $sender];
            }
        }

        return $by;
    }

    /**
     * When each invitation's link stops working, by email, while it still
     * works: its token's age plus the invitations broker's lifetime, the
     * reckoning AcceptInvitation's own check makes.
     *
     * @param  list<string>  $emails
     *
     * @return array<string, string>
     */
    private function linksWorkUntil(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $minutes = (int) config('auth.passwords.invitations.expire');
        $until = [];

        foreach (DB::table((string) config('auth.passwords.invitations.table'))->whereIn('email', $emails)->pluck('created_at', 'email') as $email => $createdAt) {
            $ends = Carbon::parse((string) $createdAt)->addMinutes($minutes);

            if ($ends->isFuture()) {
                $until[(string) $email] = $ends->toIso8601String();
            }
        }

        return $until;
    }

    /**
     * Reads the security log for display only — never to decide anything.
     *
     * @param  list<int>  $ids
     *
     * @return array<int, string>
     */
    private function latest(SecurityEventType $type, array $ids): array
    {
        /** @var array<int, string> */
        return DB::table('security_events')
            ->where('type', $type->value)
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->selectRaw('user_id, max(last_at) as at')
            ->pluck('at', 'user_id')
            ->all();
    }
}
