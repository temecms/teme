<?php

/**
 * Writes each change to a region's Custom HTML code to the security log,
 * with the code and hosts before and after, as site code's changes are.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\SiteCode;

use App\Domain\Security\SecurityEventType;
use App\Domain\Security\SecurityLog;

/**
 * The security log's record of a region's Custom HTML code
 * (docs/architecture/security.md): one event a card, its context flat, as
 * the log's context holds plain values only.
 */
final class RegionCodeLog
{
    /**
     * Logs each change saved into a revision: added, changed or removed.
     *
     * @param  list<array{kind: 'added'|'changed'|'removed', id: string, before: array{code: string, origins: list<string>, on: bool}, after: array{code: string, origins: list<string>, on: bool}}>  $changes
     */
    public static function saved(array $changes, int|string|null $actorId, string $contentId, string $region, string $revisionId): void
    {
        foreach ($changes as $change) {
            $type = match ($change['kind']) {
                'added' => SecurityEventType::SiteCodeCardAdded,
                'changed' => SecurityEventType::SiteCodeCardChanged,
                'removed' => SecurityEventType::SiteCodeCardRemoved,
            };

            self::record($type, $change, $actorId, $contentId, $region, $revisionId);
        }
    }

    /**
     * Logs each change a publish puts on the site.
     *
     * @param  list<array{kind: 'added'|'changed'|'removed', id: string, before: array{code: string, origins: list<string>, on: bool}, after: array{code: string, origins: list<string>, on: bool}}>  $changes
     */
    public static function published(array $changes, int|string|null $actorId, string $contentId, string $region, string $revisionId): void
    {
        foreach ($changes as $change) {
            self::record(SecurityEventType::SiteCodeCardPublished, $change, $actorId, $contentId, $region, $revisionId);
        }
    }

    /**
     * @param  array{kind: 'added'|'changed'|'removed', id: string, before: array{code: string, origins: list<string>, on: bool}, after: array{code: string, origins: list<string>, on: bool}}  $change
     */
    private static function record(SecurityEventType $type, array $change, int|string|null $actorId, string $contentId, string $region, string $revisionId): void
    {
        app(SecurityLog::class)->record($type, $actorId, context: [
            'region' => $region,
            'content' => $contentId,
            'card' => $change['id'],
            'revision' => $revisionId,
            'change' => $change['kind'],
            'before_code' => $change['before']['code'],
            'after_code' => $change['after']['code'],
            'before_origins' => implode(' ', $change['before']['origins']),
            'after_origins' => implode(' ', $change['after']['origins']),
            'before_on' => $change['before']['on'],
            'after_on' => $change['after']['on'],
        ]);
    }
}
