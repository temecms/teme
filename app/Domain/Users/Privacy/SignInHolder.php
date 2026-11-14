<?php

declare(strict_types=1);

namespace App\Domain\Users\Privacy;

use App\Domain\Users\AccountSessions;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

/**
 * How a person signs in: their open sessions and any password reset they
 * asked for. Exported as counts and dates, never a token or a session's
 * contents; erased by ending the sessions and deleting the reset.
 */
final class SignInHolder implements PersonalDataHolder
{
    public function key(): string
    {
        return 'sign-in';
    }

    public function label(): string
    {
        return __('Signing in');
    }

    public function export(PersonalDataSubject $subject, int $page): ExportPage
    {
        $values = [];

        if ($subject->accountId !== null) {
            $sessions = DB::table($this->sessions())->where('user_id', (int) $subject->accountId);
            $count = (clone $sessions)->count();

            if ($count > 0) {
                $latest = (clone $sessions)->orderByDesc('last_activity')->first(['last_activity', 'ip_address']);
                $values[__('Open sessions')] = (string) $count;
                $values[__('Last active')] = CarbonImmutable::createFromTimestamp((int) $latest?->last_activity)->toIso8601String();
                $values[__('Last IP address')] = (string) $latest?->ip_address;
            }
        }

        $reset = DB::table('password_reset_tokens')->whereRaw('lower(email) = ?', [$subject->email])->value('created_at');

        if ($reset !== null) {
            $values[__('Password reset asked for')] = CarbonImmutable::parse((string) $reset)->toIso8601String();
        }

        $values = array_filter($values, fn (string $value): bool => $value !== '');

        return new ExportPage($values === [] ? [] : [new ExportGroup(__('Signing in'), $values)], true);
    }

    public function erase(PersonalDataSubject $subject, int $page): ErasePage
    {
        $removed = 0;
        $user = $subject->accountId === null ? null : User::query()->find((int) $subject->accountId);

        if ($user !== null) {
            // Counted first: ending them returns nothing, and deletes rows only with the database driver.
            if (config('session.driver') === 'database') {
                $removed += DB::table($this->sessions())->where('user_id', $user->id)->count();
            }

            AccountSessions::endAllFor($user);
        }

        // Laravel keeps the address as the account had it, not lower-cased.
        $removed += DB::table('password_reset_tokens')->whereRaw('lower(email) = ?', [$subject->email])->delete();

        return new ErasePage($removed, 0, [], true);
    }

    private function sessions(): string
    {
        return (string) config('session.table', 'sessions');
    }
}
