<?php

/**
 * The one place that decides who receives a notice (I-3375): whether the
 * kind exists on this site now, the site's switch, who may receive it and
 * each person's own choice. Read again as the worker sends the notice
 * (SentAsNotice::shouldSend()), so a choice made after it was queued holds.
 * It reads only.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Notices\Queries;

use App\Domain\Notices\NoticePreferences;
use App\Domain\Notices\NoticeRegistry;
use App\Domain\Plugins\Notices\DeclaredNotices;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Teme\Contracts\Notices\NoticeKind;

final class NoticeChoices
{
    public function __construct(private readonly NoticeRegistry $registry) {}

    /**
     * The channels this recipient gets this kind by, in the kind's order;
     * none for an unregistered kind, one not offered here, or someone who may
     * not receive it.
     *
     * @return list<string>
     */
    public function channelsFor(object $notifiable, string $kind): array
    {
        $declared = $this->registry->get($kind);

        if ($declared === null || ! $this->offered($kind)) {
            return [];
        }

        if (! $notifiable instanceof User) {
            // An address with no account: a required email kind only (Q3 A).
            return $declared->required && in_array(NoticeKind::MAIL, $declared->channels, true) ? [NoticeKind::MAIL] : [];
        }

        // The Operation sending an invitation, a reset link or a password
        // notice chose the person, who may not be verified yet.
        if ($declared->required && $declared->audience === null) {
            return $declared->channels;
        }

        if ($notifiable->email_verified_at === null || $notifiable->suspended_at !== null) {
            return [];
        }

        if ($declared->audience !== null && ! $notifiable->can($declared->audience)) {
            return [];
        }

        if ($declared->required) {
            return $declared->channels;
        }

        $sends = $this->registry->rulesFor($kind)->sends;

        if ($sends !== null && ! ($sends)()) {
            return [];
        }

        $chosen = (array) (PreferencesFor::user($notifiable)[NoticePreferences::PREFIX.$kind] ?? []);

        return array_values(array_filter($declared->channels, fn (string $channel): bool => in_array($channel, $chosen, true)));
    }

    /**
     * Whether this person gets this kind by any channel.
     */
    public function wants(User $user, string $kind): bool
    {
        return $this->channelsFor($user, $kind) !== [];
    }

    /**
     * The rows of Account › Notifications for this person: every listed,
     * offered kind whose audience they have, with its label worded for them,
     * each channel and whether it is on, and whether the site switched it off.
     *
     * @return list<array{key: string, label: string, channels: list<array{key: string, label: string, on: bool}>, disabled: bool, switchedOff: string|null}>
     */
    public function forScreen(User $user): array
    {
        $preferences = PreferencesFor::user($user);
        $rows = [];

        foreach ($this->registry->listed() as $kind) {
            if (! $this->offered($kind->key) || ($kind->audience !== null && ! $user->can($kind->audience))) {
                continue;
            }

            $rules = $this->registry->rulesFor($kind->key);
            $chosen = (array) ($preferences[NoticePreferences::PREFIX.$kind->key] ?? []);
            $disabled = $rules->sends !== null && ! ($rules->sends)();

            $rows[] = [
                'key' => NoticePreferences::PREFIX.$kind->key,
                'label' => $rules->labelFor !== null ? ($rules->labelFor)($user) : __($kind->label),
                'channels' => array_map(fn (string $channel): array => [
                    'key' => $channel,
                    'label' => $channel === NoticeKind::MAIL ? __('Email') : __('Inbox'),
                    'on' => in_array($channel, $chosen, true),
                ], $kind->channels),
                'disabled' => $disabled,
                'switchedOff' => $disabled && $rules->switchedOff !== null ? __($rules->switchedOff) : null,
            ];
        }

        return $rows;
    }

    /**
     * The rows of Account › Notifications as the screen groups them: core's
     * own kinds, then each enabled plugin's under its label, in the order
     * the registry lists them (docs/plans/plugin-sockets-3.md).
     *
     * @return array{core: list<array{key: string, label: string, channels: list<array{key: string, label: string, on: bool}>, disabled: bool, switchedOff: string|null}>, plugins: list<array{name: string, plugin: string, notices: list<array{key: string, label: string, channels: list<array{key: string, label: string, on: bool}>, disabled: bool, switchedOff: string|null}>}>}
     *
     * @since 1.0.0
     */
    public function grouped(User $user): array
    {
        $declared = app(DeclaredNotices::class);
        $core = [];
        $plugins = [];

        foreach ($this->forScreen($user) as $row) {
            $owner = $declared->owner(substr($row['key'], strlen(NoticePreferences::PREFIX)));

            if ($owner === null) {
                $core[] = $row;

                continue;
            }

            $plugins[$owner['name']] ??= ['name' => $owner['name'], 'plugin' => $owner['label'], 'notices' => []];
            $plugins[$owner['name']]['notices'][] = $row;
        }

        return ['core' => $core, 'plugins' => array_values($plugins)];
    }

    /**
     * The preference keys the screen offers this person and doesn't disable:
     * what it may save.
     *
     * @return list<string>
     */
    public function keysFor(User $user): array
    {
        $keys = [];

        foreach ($this->forScreen($user) as $row) {
            if (! $row['disabled']) {
                $keys[] = $row['key'];
            }
        }

        return $keys;
    }

    private function offered(string $kind): bool
    {
        $offered = $this->registry->rulesFor($kind)->offered;

        return $offered === null || ($offered)();
    }
}
