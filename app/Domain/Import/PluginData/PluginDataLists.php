<?php

/**
 * The companion's extension route as import lists
 * (docs/specs/companion-extension-route.md): for each plugin, one paged
 * list per declared post type, then its people's meta, then its terms'
 * meta, each asking only for the declared keys and naming the plugin it is
 * read for; and the map's reference for each record.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\PluginData;

use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\SourceRef;
use InvalidArgumentException;
use Teme\Contracts\Import\WordPressRecord;

final readonly class PluginDataLists
{
    /** The companion's export API that first has the extension route. */
    public const int API = 2;

    /** The route's name under teme/v1/. */
    public const string ROUTE = 'extension';

    /** The map's object type for every plugin's record. */
    public const string TYPE = 'plugin';

    /** Each list kind of the route, the record kind it holds as the contract names it, and its page size: the route's largest. */
    private const array LISTS = [
        ['posts', WordPressRecord::POST, 200],
        ['usermeta', WordPressRecord::USER, 500],
        ['termmeta', WordPressRecord::TERM, 500],
    ];

    /**
     * Returns whether the source's companion answers the extension route.
     */
    public static function answered(string $sourceId): bool
    {
        return (int) ImportSource::query()->whereKey($sourceId)->value('api_version') >= self::API;
    }

    /**
     * Returns the lists read for this plugin, in their order.
     *
     * @return list<ImportList>
     */
    public static function of(PluginImport $import): array
    {
        $lists = [];
        $declared = $import->imports;

        [[$posts, , $postLimit], [$people, , $peopleLimit], [$terms, , $termLimit]] = self::LISTS;

        foreach ($declared['postTypes'] as $type => $meta) {
            $lists[] = new ImportList(self::ROUTE, ['plugin' => $import->code, 'kind' => $posts, 'type' => $type, 'meta' => implode(',', $meta)], $postLimit);
        }

        if ($declared['userMeta'] !== []) {
            $lists[] = new ImportList(self::ROUTE, ['plugin' => $import->code, 'kind' => $people, 'keys' => implode(',', $declared['userMeta'])], $peopleLimit);
        }

        if ($declared['termMeta'] !== []) {
            $lists[] = new ImportList(self::ROUTE, ['plugin' => $import->code, 'kind' => $terms, 'keys' => implode(',', $declared['termMeta'])], $termLimit);
        }

        return $lists;
    }

    /**
     * Returns the map's reference for one item of a list:
     * `plugin:<code>:<kind>:<type>:<id>`, the type empty for a person.
     *
     * @param  array<string, mixed>  $item
     */
    public static function ref(ImportList $list, array $item): SourceRef
    {
        $kind = self::kindOf($list);
        $type = match ($kind) {
            WordPressRecord::POST => (string) ($list->query['type'] ?? ''),
            WordPressRecord::TERM => (string) ($item['taxonomy'] ?? ''),
            default => '',
        };

        return SourceRef::of(self::TYPE, implode(':', [(string) ($list->query['plugin'] ?? ''), $kind, $type, (int) ($item['id'] ?? 0)]));
    }

    /**
     * Reads a map reference back: the plugin's code, the record's kind, its
     * type (null for a person) and WordPress's ID.
     *
     * @return array{code: string, kind: string, type: string|null, id: int}
     *
     * @throws InvalidArgumentException for a reference this part didn't make
     */
    public static function parse(SourceRef $ref): array
    {
        $parts = explode(':', $ref->id);

        if ($ref->type !== self::TYPE || count($parts) !== 4 || ! in_array($parts[1], array_column(self::LISTS, 1), true) || preg_match('/^\d+$/', $parts[3]) !== 1) {
            throw new InvalidArgumentException("[{$ref->key()}] isn’t a plugin’s record.");
        }

        return ['code' => $parts[0], 'kind' => $parts[1], 'type' => $parts[2] === '' ? null : $parts[2], 'id' => (int) $parts[3]];
    }

    /**
     * Returns the record kind a list holds, as the contract names it.
     */
    public static function kindOf(ImportList $list): string
    {
        foreach (self::LISTS as [$route, $kind]) {
            if (($list->query['kind'] ?? null) === $route) {
                return $kind;
            }
        }

        return WordPressRecord::POST;
    }
}
