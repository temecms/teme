<?php

declare(strict_types=1);

namespace App\Domain\Updates\Updater;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The header the updater signs with the app key to ask /up which Teme answers (I-3326), and how the site checks it: a time within the last minute and its digest.
 */
final class ProbeSignature
{
    public const string HEADER = 'X-Teme-Probe';

    public const int WINDOW_SECONDS = 60;

    /** Returns the header's value for a probe made at the Unix time $at. */
    public static function sign(int $at): string
    {
        return $at.'.'.hash_hmac('sha256', "teme-version-probe|{$at}", (string) config('app.key'));
    }

    /** Whether a header value is a probe signed with this site's key within the last minute. */
    public static function valid(string $header): bool
    {
        $at = Str::before($header, '.');

        if (preg_match('/^\d{1,12}$/', $at) !== 1 || abs(Carbon::now()->getTimestamp() - (int) $at) > self::WINDOW_SECONDS) {
            return false;
        }

        return hash_equals(self::sign((int) $at), $header);
    }
}
