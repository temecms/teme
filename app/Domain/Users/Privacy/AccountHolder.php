<?php

declare(strict_types=1);

namespace App\Domain\Users\Privacy;

use App\Domain\Preferences\PreferenceDefinition;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Carbon\CarbonInterface;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * A person's account: exported with its details and preferences, never a
 * secret, and kept on erasure, since deleting an account reassigns its
 * content, a decision made under Users (I-3375).
 */
final class AccountHolder implements PersonalDataHolder
{
    public function __construct(private readonly PreferenceRegistry $preferences) {}

    public function key(): string
    {
        return 'account';
    }

    public function label(): string
    {
        return __('Account');
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        $user = $this->account($subject);

        if ($user === null) {
            return new ExportPage([], true);
        }

        $account = array_filter([
            __('Name') => (string) $user->name,
            __('Email') => (string) $user->email,
            __('Profile address') => (string) $user->slug,
            __('Biography') => (string) $user->getAttribute('bio'),
            __('Created') => self::date($user->created_at),
            __('Email verified') => self::date($user->email_verified_at),
            __('Invited') => self::date($user->invited_at),
            __('Suspended') => self::date($user->suspended_at),
            // Whether it is on, never the secret or the codes.
            __('Two-factor sign-in') => $user->getAttribute('two_factor_confirmed_at') === null ? __('Off') : __('On'),
        ], fn (string $value): bool => $value !== '');

        $preferences = [];

        foreach (PreferencesFor::user($user) as $key => $value) {
            $definition = $this->preferences->get($key);

            if ($definition !== null) {
                $preferences[__($definition->label)] = self::preference($definition, $value);
            }
        }

        return new ExportPage([new ExportGroup(__('Your account'), $account), new ExportGroup(__('Preferences'), $preferences)], true);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        if ($this->account($subject) === null) {
            return new ErasePage(0, 0, [], true);
        }

        return new ErasePage(0, 1, [__('The account is kept. To remove it, delete it under Users.')], true);
    }

    private function account(PersonalDataSubject $subject): ?User
    {
        return $subject->accountId === null ? null : User::query()->find((int) $subject->accountId);
    }

    /**
     * @param  bool|int|string|list<string>  $value
     */
    private static function preference(PreferenceDefinition $definition, bool|int|string|array $value): string
    {
        return match (true) {
            is_bool($value) => $value ? __('On') : __('Off'),
            is_array($value) => implode(', ', $value),
            $definition->type === 'select' && array_key_exists($value, $definition->options) => __($definition->options[$value]),
            default => (string) $value,
        };
    }

    private static function date(mixed $value): string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : '';
    }
}
