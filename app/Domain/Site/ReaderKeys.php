<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\ReaderPasswords;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The keys a reader holds to items protected by a password (I-1700), read
 * from their cookie, never a session, which public requests don't have.
 * Each key opens one item while its password stays as it is.
 */
final readonly class ReaderKeys
{
    /** The cookie's name. */
    public const string COOKIE = 'teme_unlock';

    /** The most keys one cookie keeps, newest first. */
    public const int MOST = 20;

    /** How long the cookie lives after the last unlock, as WordPress keeps its own. */
    public const int DAYS = 10;

    /** A key: 16 bytes of base64url, unpadded. */
    private const string KEY = '/^[A-Za-z0-9_-]{22}$/';

    /**
     * @param  list<string>  $keys
     */
    private function __construct(private array $keys) {}

    /**
     * The keys this request carries; anything else in the cookie is ignored.
     */
    public static function from(Request $request): self
    {
        $value = $request->cookies->get(self::COOKIE);

        if (! is_string($value) || $value === '' || strlen($value) > self::MOST * 23) {
            return self::none();
        }

        $keys = array_filter(explode('.', $value), fn (string $key): bool => preg_match(self::KEY, $key) === 1);

        return new self(array_slice(array_values(array_unique($keys)), 0, self::MOST));
    }

    /**
     * No keys: what every caller but a reader's own request gets.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Whether a key held opens the item, its password as it is now, under
     * this site's key. Every key is compared, in constant time, so how long
     * the answer takes says nothing of which matched.
     */
    public function opens(Content $content): bool
    {
        $wanted = ReaderPasswords::key($content);

        if ($wanted === null) {
            return false;
        }

        $opens = false;

        foreach ($this->keys as $key) {
            $opens = hash_equals($wanted, $key) || $opens;
        }

        return $opens;
    }

    /**
     * The same keys with this one first; past MOST, the oldest goes.
     */
    public function with(string $key): self
    {
        return new self(array_slice([$key, ...array_values(array_diff($this->keys, [$key]))], 0, self::MOST));
    }

    /**
     * The cookie that keeps these keys: ten days, for the whole site, never
     * read by scripts or sent with another site's form, and over HTTPS only
     * where the site is. Laravel's cookie encryption isn't on the public
     * site's requests, and a key needs none: it says nothing of the password.
     */
    public function cookie(bool $secure): Cookie
    {
        return Cookie::create(self::COOKIE, implode('.', $this->keys), now()->addDays(self::DAYS), '/', null, $secure, true, false, Cookie::SAMESITE_LAX);
    }
}
