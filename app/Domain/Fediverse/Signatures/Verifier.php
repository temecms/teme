<?php

/**
 * Verifies a signed request from another server (proposal, Settled 5),
 * draft-cavage or RFC 9421 by its headers. Draft-cavage: the Signature
 * header parses and covers the request target, host, date and, for a POST,
 * digest. RFC 9421: Signature-Input names the method, the target (or the
 * authority and path) and, for a POST, content-digest, created within the
 * window. Either way the digest is the body's, the key belongs to the
 * activity's actor, and the signature opens with that key. A key that no
 * longer matches is fetched again, at most once in five minutes.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Signatures;

use Carbon\CarbonImmutable;
use gapple\StructuredFields\Bytes;
use gapple\StructuredFields\Dictionary;
use gapple\StructuredFields\InnerList;
use gapple\StructuredFields\Item;
use gapple\StructuredFields\Parameters;
use gapple\StructuredFields\Parser;
use gapple\StructuredFields\Serializer;
use Illuminate\Support\Facades\Cache;
use Teme\Contracts\Routes\PublicRequest;
use Throwable;

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

        if (isset($headers['signature-input'])) {
            return $this->verifyRfc9421($request, $headers);
        }

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

        return $this->opened($request, $signature['keyId'], $string, $signature['signature']);
    }

    /**
     * RFC 9421's half: the first Signature-Input label the Signature header
     * also carries, its covered components, created and expires, and the
     * signature base rebuilt as the RFC writes it.
     *
     * @param  array<string, string>  $headers
     *
     * @throws SignatureRefused
     */
    private function verifyRfc9421(PublicRequest $request, array $headers): VerifiedSender
    {
        try {
            $inputs = iterator_to_array(Parser::parseDictionary($headers['signature-input']));
            $signatures = iterator_to_array(Parser::parseDictionary($headers['signature'] ?? ''));
        } catch (Throwable) {
            throw SignatureRefused::because('The signature header is missing or does not parse.');
        }

        $label = null;

        foreach ($inputs as $name => $input) {
            $signed = $signatures[$name] ?? null;

            if ($input instanceof InnerList && $signed instanceof Item && $signed->getValue() instanceof Bytes) {
                $label = (string) $name;

                break;
            }
        }

        if ($label === null) {
            throw SignatureRefused::because('The signature header is missing or does not parse.');
        }

        /** @var InnerList $input */
        $input = $inputs[$label];
        /** @var Item $signed */
        $signed = $signatures[$label];
        $params = self::parameters($input);
        $components = [];

        foreach ($input->getValue() as $component) {
            $name = $component instanceof Item ? $component->getValue() : null;

            // A component with parameters (;sf, ;key, ;req) is not one Teme reads.
            if (! is_string($name) || self::parameters($component) !== []) {
                throw SignatureRefused::because('The signature header is missing or does not parse.');
            }

            $components[] = strtolower($name);
        }

        $alg = $params['alg'] ?? 'rsa-v1_5-sha256';

        if ($alg !== 'rsa-v1_5-sha256') {
            throw SignatureRefused::because('The signature algorithm is not one Teme verifies.');
        }

        $isPost = strtoupper($request->method) === 'POST';
        $targeted = in_array('@target-uri', $components, true)
            || (in_array('@authority', $components, true) && array_intersect(['@path', '@request-target'], $components) !== []);

        if (! in_array('@method', $components, true) || ! $targeted || ($isPost && ! in_array('content-digest', $components, true))) {
            throw SignatureRefused::because('The signature has not covered every component it must.');
        }

        if ($isPost && ! self::contentDigestMatches($headers['content-digest'] ?? '', $request->rawBody)) {
            throw SignatureRefused::because('The digest is not the body’s.');
        }

        $created = $params['created'] ?? null;
        $expires = $params['expires'] ?? null;

        if (! is_int($created) || abs(now()->getTimestamp() - $created) > self::WINDOW_SECONDS || (is_int($expires) && $expires < now()->getTimestamp())) {
            throw SignatureRefused::because('The date is outside the window.');
        }

        $keyId = $params['keyid'] ?? null;

        if (! is_string($keyId) || $keyId === '') {
            throw SignatureRefused::because('The signature header is missing or does not parse.');
        }

        $lines = [];

        foreach ($components as $component) {
            $lines[] = '"'.$component.'": '.self::componentValue($component, $request, $headers);
        }

        // The parameters line is the member as Structured Fields serialize it, without its label.
        $member = Serializer::serializeDictionary(Dictionary::fromArray([$label => $input]));
        $lines[] = '"@signature-params": '.substr($member, strlen($label) + 1);

        return $this->opened($request, $keyId, implode("\n", $lines), (string) $signed->getValue());
    }

    /**
     * The key's owner, fetched again once when the key no longer opens the
     * signature; the sender once the owner is the activity's actor and the
     * signature opens.
     *
     * @throws SignatureRefused
     */
    private function opened(PublicRequest $request, string $keyId, string $string, string $signature): VerifiedSender
    {
        $actor = strtoupper($request->method) === 'POST' ? self::activityActor($request->rawBody) : null;
        $owner = $this->owners->owner($keyId);

        // A key that no longer opens the signature may have changed: asked for afresh, once in five minutes.
        if ($owner !== null && ! $this->opens($string, $signature, $owner) && $this->mayFetchAgain($keyId)) {
            $owner = $this->owners->owner($keyId, fresh: true);
        }

        if ($owner === null) {
            throw SignatureRefused::because('Nobody answers for the signature’s key.');
        }

        if ($actor !== null && $actor !== $owner->ownerUri) {
            throw SignatureRefused::because('The key does not belong to the activity’s actor.');
        }

        if (! $this->opens($string, $signature, $owner)) {
            throw SignatureRefused::because('The signature does not open with the key.');
        }

        return new VerifiedSender($owner->keyId, $owner->ownerUri);
    }

    /**
     * One covered component's value for RFC 9421's signature base. The
     * scheme is https: Teme answers the fediverse only there (B-30).
     *
     * @param  array<string, string>  $headers
     *
     * @throws SignatureRefused
     */
    private static function componentValue(string $component, PublicRequest $request, array $headers): string
    {
        $query = http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
        $query = $query === '' ? '' : '?'.$query;
        $authority = strtolower(trim($headers['host'] ?? ''));

        return match ($component) {
            '@method' => strtoupper($request->method),
            '@target-uri' => 'https://'.$authority.$request->path.$query,
            '@authority' => $authority,
            '@scheme' => 'https',
            '@path' => $request->path,
            '@query' => $query === '' ? '?' : $query,
            '@request-target' => $request->path.$query,
            default => str_starts_with($component, '@') || ! isset($headers[$component])
                ? throw SignatureRefused::because('A covered header is missing.')
                : trim($headers[$component]),
        };
    }

    /**
     * A member's or component's parameters, by name.
     *
     * @return array<string, mixed>
     */
    private static function parameters(InnerList|Item $tuple): array
    {
        $parameters = $tuple->getParameters();

        return $parameters instanceof Parameters ? iterator_to_array($parameters) : [];
    }

    /**
     * Whether the Content-Digest header's sha-256 or sha-512 is the body's.
     */
    private static function contentDigestMatches(string $header, string $body): bool
    {
        try {
            $digests = iterator_to_array(Parser::parseDictionary($header));
        } catch (Throwable) {
            return false;
        }

        foreach (['sha-256' => 'sha256', 'sha-512' => 'sha512'] as $name => $algorithm) {
            $digest = $digests[$name] ?? null;

            if ($digest instanceof Item && $digest->getValue() instanceof Bytes) {
                return hash_equals(hash($algorithm, $body, true), (string) $digest->getValue());
            }
        }

        return false;
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
