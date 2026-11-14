<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases\Users;

use App\Domain\Import\Models\ImportMapEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * What an import's map says of Teme's people: whether an account of a
 * source already became a given person, and whether that source made them
 * or found them here by their email address (I-1706).
 */
final readonly class ImportedPeople
{
    /**
     * Whether an account of this source, other than $exceptObjectId,
     * already became this person, made or matched.
     */
    public function mappedFrom(string $sourceId, string $userId, ?string $exceptObjectId = null): bool
    {
        return $this->rows($sourceId, $userId)
            ->when($exceptObjectId !== null, fn (Builder $query): Builder => $query->where('object_id', '!=', $exceptObjectId))
            ->exists();
    }

    /**
     * Whether this source made this person, rather than finding them here.
     */
    public function madeBy(string $sourceId, string $userId): bool
    {
        return $this->rows($sourceId, $userId)
            ->whereRaw('not (facts @> ?::jsonb)', ['{"matched": true}'])
            ->exists();
    }

    /**
     * @return Builder<ImportMapEntry>
     */
    private function rows(string $sourceId, string $userId): Builder
    {
        // Through the map's index from a Teme item back to what it came from.
        return ImportMapEntry::query()
            ->where('teme_type', 'user')
            ->where('teme_id', $userId)
            ->where('source_id', $sourceId)
            ->where('object_type', 'user');
    }
}
