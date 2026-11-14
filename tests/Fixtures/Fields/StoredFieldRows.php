<?php

declare(strict_types=1);

namespace Tests\Fixtures\Fields;

use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A site's own fields written straight to the table, as another process's
 * change would leave them, for the stored fields' tests, and the stored
 * type movie they are usually on. Kept here, not in tests/Pest.php, which
 * another slice changes at the same time.
 */
final class StoredFieldRows
{
    /**
     * A file entry for a stored field: Text on movies, unless told otherwise.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    public static function entry(string $key, string $handle, array $overrides = []): array
    {
        return array_replace([
            'key' => $key, 'type' => 'movie', 'handle' => $handle, 'label' => Str::headline($handle), 'help' => '',
            'fieldType' => 'text', 'settings' => [], 'required' => false, 'search' => false, 'filterable' => false, 'show' => 'after',
        ], $overrides);
    }

    /**
     * Writes the entry's row at this position, removed when asked, puts the
     * cache store's copy as a change does, and has this process's registry
     * look again.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function store(array $entry, int $position, bool $removed = false): FieldDefinition
    {
        $definition = FieldDefinition::fromArray($entry)->withPosition($position);
        app(StoredFields::class)->insert($definition);

        if ($removed) {
            DB::table(StoredFields::TABLE)->where('key', $definition->key)->update(['removed_at' => now()]);
        }

        self::remember();

        return $definition;
    }

    /**
     * Rewrites the stored row's entry with these changes, as an edit would.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function change(string $key, array $changes): void
    {
        $current = app(StoredFields::class)->find($key);
        assert($current !== null);
        $next = FieldDefinition::fromArray([...$current->toArray(), ...$changes])->withPosition($current->position);
        app(StoredFields::class)->update(new FieldDefinition(...[...get_object_vars($next), 'removedAt' => $current->removedAt, 'removedBy' => $current->removedBy]));
        self::remember();
    }

    /**
     * Marks the field removed, as removing it does.
     */
    public static function remove(string $key): void
    {
        DB::table(StoredFields::TABLE)->where('key', $key)->update(['removed_at' => now()]);
        self::remember();
    }

    /**
     * The stored type movie, with no feature that gives fields, registered
     * in this process. Its permissions are the shared ones.
     */
    public static function movieType(): void
    {
        ctStoreRow(ctDefinition(['key' => 'movie', 'singular' => 'Movie', 'plural' => 'Movies', 'segment' => 'movies', 'icon' => 'film', 'taxonomies' => [], 'supports' => []]));
        app(ContentTypeRegistry::class)->refreshStored();
    }

    private static function remember(): void
    {
        app(StoredFields::class)->remember();
        app(FieldRegistry::class)->refreshStored();
    }
}
