<?php

/**
 * Takes an activity POSTed to one of the site's inboxes, in a fixed order
 * (docs/plans/fediverse-actors.md, D8): a blocked server 403 before
 * anything else; a body over 256 KB 413, by its Content-Length first; too
 * many requests from an address 429; a bad signature 401; too many from the
 * verified server 429; the fediverse paused 503; an activity whose id isn't
 * on its signer's server 400; else it is queued once per id and answered 202.
 * Nothing is written here.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Inbox;

use App\Domain\Fediverse\BlockedHosts;
use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Jobs\ProcessInboxActivity;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Queries\FediverseDocuments;
use App\Domain\Fediverse\Signatures\SignatureRefused;
use App\Domain\Fediverse\Signatures\Verifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Teme\Contracts\Routes\PublicRequest;

final readonly class InboxGate
{
    /** The largest body taken (proposal, P2). */
    public const int MAX_BYTES = 262_144;

    /** Requests a minute per address and per remote server (proposal, P2). */
    public const int PER_MINUTE = 120;

    /** How long an activity id is remembered, so a second delivery is taken once. */
    public const int SEEN_SECONDS = 7 * 86_400;

    public function __construct(private Verifier $verifier) {}

    /**
     * Whether the path is an inbox of this site: the shared inbox, or an
     * enabled actor's own, new or imported.
     */
    public function isInbox(string $url): bool
    {
        if ($url === self::sharedInbox() || $url === self::wordPressSharedInbox()) {
            return true;
        }

        return FediverseActor::query()->where('enabled', true)->whereRaw("urls->>'inbox' = ?", [$url])->exists();
    }

    public function receive(PublicRequest $request): InboxAnswer
    {
        $claimed = self::claimedHost($request);

        if ($claimed !== null && BlockedHosts::blocks($claimed)) {
            return new InboxAnswer(403);
        }

        if (self::tooLarge($request)) {
            return new InboxAnswer(413);
        }

        if (! self::withinLimit('fediverse-inbox:address:'.$request->clientAddress)) {
            return new InboxAnswer(429, ['Retry-After' => '60']);
        }

        // Signed for this site only: a request signed for another server
        // isn't replayed here, whatever Host the web server passes on.
        if (! self::addressedHere($request)) {
            return new InboxAnswer(401);
        }

        try {
            $sender = $this->verifier->verify($request);
        } catch (SignatureRefused) {
            return new InboxAnswer(401);
        }

        if (BlockedHosts::blocks($sender->host())) {
            return new InboxAnswer(403);
        }

        // Counted only once verified, so forged requests naming a server
        // can't use up its limit.
        if (! self::withinLimit('fediverse-inbox:host:'.$sender->host())) {
            return new InboxAnswer(429, ['Retry-After' => '60']);
        }

        if (! FediverseSwitch::on()) {
            return new InboxAnswer(503, ['Retry-After' => '3600']);
        }

        $activity = json_decode($request->rawBody, true);

        if (! is_array($activity) || ! is_string($activity['id'] ?? null) || ! is_string($activity['type'] ?? null)) {
            return new InboxAnswer(400);
        }

        if (strtolower((string) parse_url($activity['id'], PHP_URL_HOST)) !== $sender->host()) {
            return new InboxAnswer(400);
        }

        if (Cache::add('fediverse:seen:'.hash('sha256', $activity['id']), true, self::SEEN_SECONDS)) {
            ProcessInboxActivity::dispatch($activity, $sender->keyId, $sender->actorUri);
        }

        return new InboxAnswer(202);
    }

    /**
     * The shared inbox's address.
     */
    public static function sharedInbox(): string
    {
        return rtrim(url('/'), '/').'/ap/inbox';
    }

    /**
     * The WordPress plugin's shared inbox, kept for imported followers.
     */
    public static function wordPressSharedInbox(): string
    {
        return rtrim(url('/'), '/').'/wp-json/activitypub/1.0/inbox';
    }

    /**
     * The server the request says it comes from: its signature key's host,
     * read without trusting it, or null when it names none.
     */
    private static function claimedHost(PublicRequest $request): ?string
    {
        $headers = array_change_key_case($request->headers, CASE_LOWER);
        // Draft-cavage names it keyId in Signature, RFC 9421 keyid in Signature-Input.
        $header = ($headers['signature'] ?? '').' '.($headers['signature-input'] ?? '');

        if (preg_match('/keyid="([^"]+)"/i', $header, $match) !== 1) {
            return null;
        }

        $host = parse_url($match[1], PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * Whether the request's Host, without its port, is this site's.
     */
    private static function addressedHere(PublicRequest $request): bool
    {
        $host = (string) (array_change_key_case($request->headers, CASE_LOWER)['host'] ?? '');
        $host = strtolower((string) preg_replace('/:\d+$/', '', trim($host)));

        return $host !== '' && $host === FediverseDocuments::host();
    }

    /**
     * Whether the body is over the limit: by the Content-Length it declares,
     * or by what was read of it when it declares none.
     */
    private static function tooLarge(PublicRequest $request): bool
    {
        $length = array_change_key_case($request->headers, CASE_LOWER)['content-length'] ?? '';

        if (ctype_digit($length) && (int) $length > self::MAX_BYTES) {
            return true;
        }

        return strlen($request->rawBody) > self::MAX_BYTES;
    }

    private static function withinLimit(string $key): bool
    {
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }
}
