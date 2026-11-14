<?php

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Phases\SourceRef;

/**
 * The import map read (I-332): an object's row, the Teme IDs objects
 * became, the hash of a source item, and whether an object is on a run's
 * work list, and how many earlier failures a run left untried.
 */
final readonly class ImportMap
{
    /**
     * The SHA-256 of a source item's JSON, its keys sorted at every level,
     * less these top-level keys, whose change means nothing for the object.
     *
     * @param  array<int|string, mixed>  $item
     * @param  list<string>  $ignore
     */
    public static function hashOf(array $item, array $ignore = []): string
    {
        $kept = array_diff_key($item, array_flip($ignore));

        return hash('sha256', json_encode(self::sorted($kept), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Whether an object is on the work list: no map row, a row marked to
     * update, or a source item that changed since it was last imported.
     */
    public static function wants(?ImportMapEntry $entry, string $hash): bool
    {
        return $entry === null || $entry->status === ImportStatus::NeedsUpdate || $entry->source_hash !== $hash;
    }

    /**
     * The object's map row, or null when it has none.
     */
    public function entry(string $sourceId, SourceRef $ref): ?ImportMapEntry
    {
        return ImportMapEntry::query()
            ->where('source_id', $sourceId)->where('object_type', $ref->type)->where('object_id', $ref->id)
            ->first();
    }

    /**
     * The Teme ID the object became, or null when it never became one.
     */
    public function temeId(string $sourceId, SourceRef $ref): ?string
    {
        $id = ImportMapEntry::query()
            ->where('source_id', $sourceId)->where('object_type', $ref->type)->where('object_id', $ref->id)
            ->whereNotNull('teme_id')
            ->value('teme_id');

        return $id === null ? null : (string) $id;
    }

    /**
     * The Teme IDs these objects of one type became, for those that became one.
     *
     * @param  list<int|string>  $ids
     *
     * @return array<int|string, string> source ID to Teme ID; a numeric source ID comes back as an integer key
     */
    public function temeIds(string $sourceId, string $type, array $ids): array
    {
        return ImportMapEntry::query()
            ->where('source_id', $sourceId)->where('object_type', $type)->whereIn('object_id', array_map(strval(...), $ids))
            ->whereNotNull('teme_id')
            ->pluck('teme_id', 'object_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * Returns how many of the source's objects failed in an earlier run and
     * weren't tried again by this one: marked for the run when it started,
     * and never met since, as a catch-up never meets what WordPress didn't
     * change.
     */
    public function failedBefore(string $sourceId, string $runId): int
    {
        return ImportMapEntry::query()
            ->where('source_id', $sourceId)
            ->where('status', ImportStatus::NeedsUpdate->value)
            ->where('outcome', ImportOutcome::Failed->value)
            ->where(fn ($rows) => $rows->whereNull('run_id')->orWhere('run_id', '<>', $runId))
            ->count();
    }

    /**
     * @param  array<int|string, mixed>  $value
     *
     * @return array<int|string, mixed>
     */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::sorted($child);
            }
        }

        return $value;
    }
}
