<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * The enabled plugins' rows, read once and kept in the cache until a plugin
 * is enabled or disabled (I-3353), as settings groups are: plain arrays of
 * each row's columns in the cache, never models.
 */
final readonly class PluginRecords
{
    /** The cache key of the enabled rows, forgotten after commit by every change to the table. */
    public const string CACHE_KEY = 'teme.plugins.enabled';

    /** PostgreSQL's code for a table that doesn't exist yet, before the first migration. */
    private const string NO_TABLE = '42P01';

    /**
     * The enabled plugins' rows, ordered by name; none before the table exists.
     *
     * @return list<PluginRecord>
     */
    public function enabled(): array
    {
        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = Cache::memo()->rememberForever(self::CACHE_KEY, fn (): array => PluginRecord::query()
                ->where('state', PluginRecord::ENABLED)
                ->orderBy('name')
                ->get()
                ->map(fn (PluginRecord $record): array => $record->getAttributes())
                ->all());
        } catch (QueryException $failed) {
            if ((string) $failed->getCode() === self::NO_TABLE) {
                return [];
            }

            throw $failed;
        }

        return array_map(fn (array $attributes): PluginRecord => (new PluginRecord)->newFromBuilder($attributes), $rows);
    }

    /**
     * After a plugin is enabled or disabled, once committed: forgets the
     * enabled rows, so the next read asks the table, and tells the queue's
     * workers, which started their plugins at boot, to restart, as
     * `queue:restart` does.
     */
    public function changed(): void
    {
        Cache::memo()->forget(self::CACHE_KEY);
        Cache::forever('illuminate:queue:restart', CarbonImmutable::now()->getTimestamp());
        app(PublicRouteRegistry::class)->flush();
    }
}
