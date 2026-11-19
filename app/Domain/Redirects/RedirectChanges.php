<?php

/**
 * What every redirect change leaves behind (I-694, I-297): an entry in the
 * security log, and the stored "not found" pages it could replace, purged
 * after commit: its source's address for an exact redirect, and every
 * stored "not found" page for a pattern, which might match any of them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects;

use App\Domain\Cache\PageTags;
use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Cache\CacheKeysFor;

final readonly class RedirectChanges
{
    public function __construct(private SecurityLog $log) {}

    /**
     * The change told to the page cache for these redirects, before and
     * after: a redirect is the site's own rule, so it is told as the site's.
     *
     * @param  list<array{source: string, kind: string}>  $redirects
     */
    public static function purged(array $redirects): CacheKeysFor
    {
        $tags = array_map(
            fn (array $redirect): string => $redirect['kind'] === 'pattern' ? RedirectLookup::NOT_FOUND_TAG : PageTags::url('/'.$redirect['source']),
            $redirects,
        );

        return new CacheKeysFor(CacheKeysFor::SITE, 'redirects', array_values(array_unique($tags)));
    }

    /**
     * A redirect as the security log keeps it: its ID, source, target in
     * words and status.
     *
     * @return array{redirect: string, from: string, to: string, status: int}
     */
    public static function described(object $redirect): array
    {
        return [
            'redirect' => (string) $redirect->id,
            'from' => '/'.$redirect->source,
            'to' => match ($redirect->target_kind) {
                RedirectTarget::CONTENT => 'item:'.$redirect->target,
                RedirectTarget::MEDIA => 'file:'.$redirect->target,
                default => (string) ($redirect->target ?? ''),
            },
            'status' => (int) $redirect->status,
        ];
    }

    /**
     * The refusal as a form shows it, under its field.
     */
    public static function refused(RedirectRefused $refused): ValidationException
    {
        return ValidationException::withMessages([$refused->field => [$refused->getMessage()]]);
    }

    /**
     * Records the change in the security log, as whoever made it.
     *
     * @param  array<string, scalar|null>  $context
     */
    public function record(SecurityEventType $type, ?string $actorId, array $context): void
    {
        $this->log->record($type, $actorId, context: $context);
    }
}
