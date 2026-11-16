<?php

declare(strict_types=1);

namespace App\Domain\Site\Insertions\Scripts;

use App\Domain\Site\Policy\PagePolicy;
use Illuminate\Support\Facades\Cache;

/**
 * The hashes of every plugin's approved inline scripts, read once and kept
 * in the cache until a plugin script Operation forgets them after its
 * commit, as SiteCodeEntries are. Plain arrays of text, so the cache table
 * holds nothing I-261 doesn't allow.
 */
final class ApprovedScripts
{
    public const string CACHE_KEY = 'plugin-scripts:approved:1';

    /**
     * The hashes approved for this plugin, as PagePolicy::hash() gives them;
     * none for a plugin with none.
     *
     * @return list<string>
     */
    public static function for(string $code): array
    {
        return self::all()[$code] ?? [];
    }

    /** Forgets the approved hashes, so the next page reads the table. */
    public static function forget(): void
    {
        Cache::memo()->forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function all(): array
    {
        /** @var array<string, list<string>> */
        return Cache::memo()->rememberForever(self::CACHE_KEY, function (): array {
            $approved = [];

            foreach (PluginScript::query()->where('state', PluginScript::APPROVED)->orderBy('id')->get(['plugin_code', 'hash']) as $script) {
                $approved[$script->plugin_code][] = $script->hash;
            }

            return $approved;
        });
    }

    /**
     * The hash a browser gives this script's text, once its parser turned
     * CR LF and CR into LF.
     */
    public static function hash(string $script): string
    {
        return PagePolicy::hash(str_replace(["\r\n", "\r"], "\n", $script));
    }
}
