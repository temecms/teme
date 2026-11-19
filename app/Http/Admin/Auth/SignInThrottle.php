<?php

/**
 * Three counters, keyed on what was submitted — never on whether an account
 * exists. Study G, S4; SECURITY.md commitments 10 and 11.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Auth;

use Illuminate\Support\Str;
use Teme\Contracts\Settings\SettingsReader;

final class SignInThrottle
{
    public function __construct(private readonly SettingsReader $settings) {}

    /**
     * @return list<array{key: string, max: int, decay: int}>
     */
    public function limitsFor(string $email, string $ip, bool $trustedDevice): array
    {
        $limits = [
            ['key' => self::accountIpKey($email, $ip), 'max' => $this->settings->int('security', 'login_attempts_per_account_ip'), 'decay' => 900],
            ['key' => 'signin:ip:'.$ip, 'max' => $this->settings->int('security', 'login_attempts_per_ip'), 'decay' => 3600],
        ];

        if (! $trustedDevice) {
            $limits[] = ['key' => self::accountKey($email), 'max' => $this->settings->int('security', 'login_untrusted_attempts_per_account'), 'decay' => 3600];
        }

        return $limits;
    }

    public static function accountKey(string $email): string
    {
        return 'signin:account:'.sha1(self::normalise($email));
    }

    public static function accountIpKey(string $email, string $ip): string
    {
        return 'signin:account-ip:'.sha1(self::normalise($email).'|'.$ip);
    }

    private static function normalise(string $email): string
    {
        return Str::lower(trim($email));
    }
}
