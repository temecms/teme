<?php

/**
 * Which plugin a stored type, taxonomy or field came from (docs/architecture/plugins.md,
 * *Content types from a plugin*): the `plugin` column of each definitions
 * table, null for the site's own. A plugin's rows are changed only by that
 * plugin, and are left out of the registries while it isn't enabled.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Stored\StoredFields;
use App\Domain\Plugins\PluginRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TypeOwners
{
    /** The tables that record an owner, by what a row is. */
    public const array TABLES = [
        'types' => StoredTypes::TABLE,
        'taxonomies' => StoredTaxonomies::TABLE,
        'fields' => StoredFields::TABLE,
    ];

    /**
     * The plugin each stored row of $kind came from, by key; the site's own
     * rows are left out.
     *
     * @param  'types'|'taxonomies'|'fields'  $kind
     *
     * @return array<string, string>
     */
    public static function of(string $kind): array
    {
        if (DB::scalar('SELECT to_regclass(?)', [self::TABLES[$kind]]) === null) {
            return [];
        }

        /** @var array<string, string> */
        return DB::table(self::TABLES[$kind])->whereNotNull('plugin')->pluck('plugin', 'key')->map(fn (mixed $plugin): string => (string) $plugin)->all();
    }

    /**
     * The keys of $kind's rows whose plugin isn't enabled: kept, with their
     * items and values, and left out of the registries. None before the
     * column exists, as a new site migrates.
     *
     * @param  'types'|'taxonomies'|'fields'  $kind
     *
     * @return list<string>
     */
    public static function dormant(string $kind): array
    {
        $table = self::TABLES[$kind];

        if (DB::scalar('SELECT '.self::recordedSql()) !== true) {
            return [];
        }

        return DB::table($table)
            ->whereRaw(self::dormantSql())
            ->orderBy('key')
            ->pluck('key')
            ->map(fn (mixed $key): string => (string) $key)
            ->all();
    }

    /**
     * A condition true for a row whose plugin isn't enabled, to read with the
     * row itself, so the registries' reads need no other statement.
     */
    public static function dormantCondition(): string
    {
        return self::dormantSql();
    }

    /**
     * An expression true once the definitions tables have their `plugin`
     * column, as one migration adds it to all three: to ask in a statement
     * the registries already make, as a new site migrates.
     */
    public static function recordedSql(): string
    {
        return "EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = to_regclass('".StoredTypes::TABLE."') AND attname = 'plugin' AND NOT attisdropped)";
    }

    private static function dormantSql(): string
    {
        return "(plugin IS NOT NULL AND plugin NOT IN (SELECT name FROM plugins WHERE state = 'enabled'))";
    }

    /**
     * The plugin this stored row came from, or null for the site's own or no row.
     *
     * @param  'types'|'taxonomies'|'fields'  $kind
     */
    public static function owner(string $kind, string $key): ?string
    {
        $plugin = DB::table(self::TABLES[$kind])->where('key', $key)->value('plugin');

        return is_string($plugin) ? $plugin : null;
    }

    /**
     * The plugin's label, or its Composer name when its code can't be read.
     */
    public static function label(string $plugin): string
    {
        $label = app(PluginRegistry::class)->find($plugin)?->manifest?->label();

        return $label === null || $label === '' ? $plugin : $label;
    }

    /**
     * What a file can't change because another plugin brought it, by the
     * file's place: a plugin's file may change only its own rows and the
     * site's own ($plugin), an import only the site's own (null).
     *
     * @param  array<mixed>  $file
     *
     * @return array<string, list<string>>
     */
    public static function problems(array $file, ?string $plugin): array
    {
        $problems = [];

        foreach (['types', 'taxonomies', 'fields'] as $kind) {
            $entries = $file[$kind] ?? [];

            if (! is_array($entries) || ! array_is_list($entries)) {
                continue;
            }

            $owners = self::of($kind);

            foreach ($entries as $i => $entry) {
                $key = is_array($entry) && is_string($entry['key'] ?? null) ? $entry['key'] : null;
                $owner = $key === null ? null : ($owners[$key] ?? null);

                if ($owner === null || $owner === $plugin) {
                    continue;
                }

                $name = $kind === 'fields'
                    ? (is_string($entry['type'] ?? null) ? $entry['type'].'.' : '').(is_string($entry['handle'] ?? null) ? $entry['handle'] : $key)
                    : (is_string($entry['plural'] ?? null) ? $entry['plural'] : $key);

                $problems["{$kind}.{$i}.key"][] = __(':name comes from the plugin :plugin, so only that plugin changes it.', ['name' => $name, 'plugin' => self::label($owner)]);
            }
        }

        return $problems;
    }

    /**
     * Marks these rows as the plugin's, taking the site's own as they are.
     *
     * @param  array{types: list<string>, taxonomies: list<string>, fields: list<string>}  $keys
     */
    public static function mark(string $plugin, array $keys): void
    {
        foreach ($keys as $kind => $list) {
            if ($list !== []) {
                DB::table(self::TABLES[$kind])->whereIn('key', $list)->update(['plugin' => $plugin]);
            }
        }
    }

    /**
     * Gives the site every row of the plugin's that $keys no longer names,
     * kept as it is with its items and values, and says which.
     *
     * @param  array{types: list<string>, taxonomies: list<string>, fields: list<string>}  $keys
     *
     * @return array{types: list<string>, taxonomies: list<string>, fields: list<string>}
     */
    public static function release(string $plugin, array $keys): array
    {
        $released = ['types' => [], 'taxonomies' => [], 'fields' => []];

        foreach ($keys as $kind => $list) {
            $query = DB::table(self::TABLES[$kind])->where('plugin', $plugin)->whereNotIn('key', $list === [] ? [''] : $list);
            $released[$kind] = $kind === 'fields'
                ? $query->clone()->orderBy('content_type')->orderBy('position')->get(['content_type', 'handle'])->map(fn (object $row): string => $row->content_type.'.'.$row->handle)->all()
                : $query->clone()->orderBy('key')->pluck('key')->map(fn (mixed $key): string => (string) $key)->all();
            $query->update(['plugin' => null]);
        }

        return $released;
    }

    /**
     * The plugin's rows of each kind, by key.
     *
     * @return array{types: list<string>, taxonomies: list<string>, fields: list<string>}
     */
    public static function ownedBy(string $plugin): array
    {
        $owned = [];

        foreach (self::TABLES as $kind => $table) {
            $owned[$kind] = DB::scalar('SELECT to_regclass(?)', [$table]) === null
                ? []
                : DB::table($table)->where('plugin', $plugin)->orderBy('key')->pluck('key')->map(fn (mixed $key): string => (string) $key)->all();
        }

        /** @var array{types: list<string>, taxonomies: list<string>, fields: list<string>} */
        return $owned;
    }

    /**
     * After a plugin's types come or go, once committed (I-3296): the cache
     * store's copies of the types and fields, then this process's registries
     * and address settings. A cache store that refuses is reported, never
     * thrown: the change has committed.
     */
    public static function changed(): void
    {
        foreach ([StoredTypes::class, StoredFields::class] as $store) {
            try {
                app($store)->remember();
            } catch (Throwable $refused) {
                report($refused);
            }
        }

        app(ContentTypeRegistry::class)->refreshStored();
        app(FieldRegistry::class)->refreshStored();
        app()->forgetInstance(AddressSettings::class);
    }

    /**
     * The items of the plugin's types, trashed ones included, by type key,
     * with each type's plural; none when it brought no type or no type has any.
     *
     * @return array<string, array{plural: string, segment: string, items: int}>
     */
    public static function items(string $plugin): array
    {
        $rows = DB::table(StoredTypes::TABLE)
            ->where('plugin', $plugin)
            ->orderBy('key')
            ->get(['key', DB::raw("COALESCE(definition ->> 'plural', key) AS plural"), DB::raw("definition ->> 'segment' AS segment")]);

        if ($rows->isEmpty()) {
            return [];
        }

        // DB::table() sees soft-deleted rows: trashed items count.
        $counts = DB::table('contents')->whereIn('type', $rows->pluck('key')->all())->groupBy('type')->selectRaw('type, count(*) AS items')->pluck('items', 'type')->all();
        $items = [];

        foreach ($rows as $row) {
            $count = (int) ($counts[$row->key] ?? 0);

            if ($count > 0) {
                $items[(string) $row->key] = ['plural' => (string) $row->plural, 'segment' => (string) $row->segment, 'items' => $count];
            }
        }

        return $items;
    }
}
