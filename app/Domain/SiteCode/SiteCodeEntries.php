<?php

declare(strict_types=1);

namespace App\Domain\SiteCode;

use App\Domain\SiteCode\Models\SiteCodeEntry;
use Illuminate\Support\Facades\Cache;

/**
 * The site code every page draws: the entries that are on, by placement, in
 * the order they were added, each with what it needs from the page's
 * content security policy (I-3375), worked out once, read once and kept in
 * the cache until a site code Operation forgets them after its commit. Plain
 * arrays of text, so the cache table holds nothing I-261 doesn't allow.
 */
final class SiteCodeEntries
{
    /** The second shape, with each entry's policy: a value cached in the first is never read. */
    public const string CACHE_KEY = 'site-code:on:2';

    /**
     * The entries that are on, keyed by placement; a placement with none is
     * absent. Each holds its code and its policy: the hashes of its inline
     * scripts and on* handlers, and its origins by directive.
     *
     * @return array<string, list<array{id: string, code: string, policy: array{scripts: list<string>, handlers: list<string>, origins: array<string, list<string>>}}>>
     */
    public static function on(): array
    {
        /** @var array<string, list<array{id: string, code: string, policy: array{scripts: list<string>, handlers: list<string>, origins: array<string, list<string>>}}>> */
        return Cache::memo()->rememberForever(self::CACHE_KEY, function (): array {
            $on = [];

            // ULIDs sort as they were made, so this is the order they were added.
            foreach (SiteCodeEntry::query()->where('enabled', true)->orderBy('id')->get(['id', 'placement', 'code', 'origins']) as $entry) {
                $on[$entry->placement][] = ['id' => $entry->id, 'code' => $entry->code, 'policy' => SiteCodePolicy::of($entry->code, $entry->origins)];
            }

            return $on;
        });
    }
}
