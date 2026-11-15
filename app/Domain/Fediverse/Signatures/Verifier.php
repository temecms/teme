<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Signatures;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Teme\Contracts\Routes\PublicRequest;
use Throwable;

/**
 * Verifies a signed request from another server, draft-cavage style
 * (proposal, Settled 5): the Signature header parses and covers the request
 * target, host, date and, for a POST, digest; the digest is the body's; the
 * date is within the window; the key belongs to the activity's actor; and
 * the signature opens with that key. A key that no longer matches is
 * fetched again, at most once in five minutes.
 */
final readonly class Verifier
{
    /** How far the Date header may be from now, either way (Mastodon's 12 hours). */
    public const int WINDOW_SECONDS = 12 * 3600;

    /** How long a key once fetched again isn't fetched again. */
    public const int REFETCH_SECONDS = 300;

    public function __construct(private KeyOwners $owners) {}

    /**
     * The request's verified sender.
     *
     * @throws SignatureRefused
     */
    public function verify(PublicRequest $request): VerifiedSender
    {
        $headers = array_change_key_case($request->headers, CASE_LOWER);
        $signature = self::parse($headers['signature'] ?? '');
        $isPost = strtoupper($request->method) === 'POST';

        $required = ['(request-target)', 'host', 'date', ...($isPost ? ['digest'] : [])];

        if (array_diff($required, $signature['headers']) !== []) {
            throw SignatureRefused::because('The signature has not covered every header it must.');
        }

        if ($isPost && ! self::digestMatches($headers['digest'] ?? '', $request->rawBody)) {
            throw SignatureRefused::because('The digest is not the body’s.');
        }

        if (! self::dateWithinWindow($headers['date'] ?? '')) {
            throw SignatureRefused::because('The date is outside the window.');
        }

        $string = self::signingString($signature['headers'], $request, $headers);
        $actor = $isPost ? self::activityActor($request->rawBody) : null;
        $owner = $this->owners->owner($signature['keyId']);

        // A key that no longer opens the signature may have changed: asked for afresh, once in five minutes.
        if ($owner !== null && ! $this->opens($string, $signature['signature'], $owner) && $this->mayFetchAgain($signature['keyId'])) {
            $owner = $this->owners->owner($signature['keyId'], fresh: true);
        }

        if ($owner === null) {
            throw SignatureRefused::because('Nobody answers for the signature’s key.');
        }

        if ($actor !== null && $actor !== $owner->ownerUri) {
            throw SignatureRefused::because('The key does not belong to the activity’s actor.');
        }

        if (! $this->opens($string, $signature['signature'], $owner)) {
            throw SignatureRefused::because('The signature does not open with the key.');
        }

        return new VerifiedSender($owner->keyId, $owner->ownerUri);
    }

    /**
     * The Signature header's parts: keyId, algorithm, the covered headers
     * (draft-cavage's default, date, when none are named) and the signature.
     *
     * @return array{keyId: string, algorithm: string, headers: list<string>, signature: string}
     *
     * @throws SignatureRefused
     */
    public static function parse(string $header): array
    {
        if (preg_match_all('/([a-zA-Z]+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER) === 0) {
            throw SignatureRefused::because('The signature header is missing or does not parse.');
        }

        $parts = [];

        foreach ($matches as $match) {
            $parts[$match[1]] = $match[2];
        }

        $signature = base64_decode($parts['signature'] ?? '', true);

        if (! isset($parts['keyId']) || $parts['keyId'] === '' || $signature === false || $signature === '') {
            throw SignatureRefused::because('The signature header is missing or does not parse.');
        }

        $algorithm = strtolower($parts['algorithm'] ?? 'rsa-sha256');

        // hs2019 names no algorithm of its own: the key decides, and Teme's keys are RSA.
        if (! in_array($algorithm, ['rsa-sha256', 'hs2019'], true)) {
            throw SignatureRefused::because('The signature algorithm is not one Teme verifies.');
        }

        $headers = preg_split('/\s+/', strtolower(trim($parts['headers'] ?? 'date'))) ?: [];

        return ['keyId' => $parts['keyId'], 'algorithm' => $algorithm, 'headers' => array_values(array_filter($headers)), 'signature' => $signature];
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, string>  $headers
     *
     * @throws SignatureRefused
     */
    private static function signingString(array $names, PublicRequest $request, array $headers): string
    {
        $lines = [];

        foreach ($names as $name) {
            if ($name === '(request-target)') {
                $query = http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
                $lines[] = $name.': '.strtolower($request->method).' '.$request->path.($query === '' ? '' : '?'.$query);

                continue;
            }

            if (! isset($headers[$name])) {
                throw SignatureRefused::because('A covered header is missing.');
            }

            $lines[] = $name.': '.trim($headers[$name]);
        }

        return implode("\n", $lines);
    }

    private static function digestMatches(string $header, string $body): bool
    {
        $expected = base64_encode(hash('sha256', $body, true));

        foreach (explode(',', $header) as $part) {
            [$algorithm, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if (strtolower($algorithm) === 'sha-256') {
                return hash_equals($expected, trim($value));
            }
        }

        return false;
    }

    private static function dateWithinWindow(string $header): bool
    {
        try {
            $date = CarbonImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', trim($header), 'UTC');
        } catch (Throwable) {
            return false;
        }

        return $date !== null && abs(now()->getTimestamp() - $date->getTimestamp()) <= self::WINDOW_SECONDS;
    }

    /**
     * The activity's actor as the body names it, or null when it names none.
     */
    private static function activityActor(string $body): ?string
    {
        $activity = json_decode($body, true);
        $actor = is_array($activity) ? ($activity['actor'] ?? null) : null;

        if (is_array($actor)) {
            $actor = $actor['id'] ?? null;
        }

        return is_string($actor) && $actor !== '' ? $actor : null;
    }

    private function opens(string $string, string $signature, KeyOwner $owner): bool
    {
        $key = openssl_pkey_get_public($owner->publicKeyPem);

        return $key !== false && openssl_verify($string, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function mayFetchAgain(string $keyId): bool
    {
        return Cache::add(self::refetchKey($keyId), true, self::REFETCH_SECONDS);
    }

    private static function refetchKey(string $keyId): string
    {
        return 'fediverse:refetch:'.hash('sha256', $keyId);
    }
}
