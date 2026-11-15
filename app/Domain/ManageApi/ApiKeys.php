<?php

declare(strict_types=1);

namespace App\Domain\ManageApi;

use App\Domain\ManageApi\Models\ApiKey;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Management API keys' secrets: making one, and finding the live key a
 * request's secret belongs to. Only the secret's SHA-256 is stored; the
 * secret itself is shown once and never kept or logged.
 */
final class ApiKeys
{
    /** Every secret starts with this, so a leaked one is easy to recognise. */
    public const string PREFIX = 'teme_';

    /** How many random base62 characters follow the prefix. */
    public const int LENGTH = 40;

    /** How much of the secret the list shows: the prefix and seven characters. */
    public const int START = 12;

    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * A new secret: the prefix and 40 base62 characters from random_bytes,
     * each byte over 247 thrown away so every character is equally likely.
     */
    public static function newSecret(): string
    {
        $characters = '';

        while (strlen($characters) < self::LENGTH) {
            foreach (str_split(random_bytes(64)) as $byte) {
                $value = ord($byte);

                if ($value < 248 && strlen($characters) < self::LENGTH) {
                    $characters .= self::ALPHABET[$value % 62];
                }
            }
        }

        return self::PREFIX.$characters;
    }

    /**
     * The hash a secret is stored and looked up by.
     */
    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * The live key this secret belongs to, or null for a secret that is
     * unknown, malformed, revoked or past its expiry, all alike.
     */
    public function findLive(string $secret): ?ApiKey
    {
        if (preg_match('/^'.self::PREFIX.'[0-9A-Za-z]{'.self::LENGTH.'}$/', $secret) !== 1) {
            return null;
        }

        $hash = self::hash($secret);
        $key = ApiKey::query()->where('token_hash', $hash)->first();

        if ($key === null || ! hash_equals($key->token_hash, $hash) || ! $key->isLive()) {
            return null;
        }

        return $key;
    }

    /**
     * The caller a request's secret signs in as, noting the key's use; null
     * for a key findLive() wouldn't find, or one whose person is suspended.
     */
    public function signIn(string $secret): ?ApiCaller
    {
        $key = $this->findLive($secret);

        if ($key === null || ! DB::table('users')->where('id', $key->user_id)->whereNull('suspended_at')->exists()) {
            return null;
        }

        $this->touch($key);

        return ApiCaller::of($key);
    }

    /**
     * Notes that the key was used, at most once a minute: a technical
     * timestamp, like a session's touch, written outside any Operation.
     */
    public function touch(ApiKey $key): void
    {
        $now = Date::now();

        if ($key->last_used_at !== null && $key->last_used_at->greaterThan($now->subMinute())) {
            return;
        }

        DB::table('api_keys')
            ->where('id', $key->id)
            ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', $now->subMinute()))
            ->update(['last_used_at' => $now]);
    }
}
