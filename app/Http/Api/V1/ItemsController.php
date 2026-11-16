<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Api\ApiNotFound;
use App\Domain\Api\ApiQuery;
use App\Domain\Api\ApiResponse;
use App\Domain\Api\ApiWords;
use App\Domain\Api\Envelope;
use App\Domain\Api\InvalidParameters;
use App\Domain\Api\Items\ItemFilters;
use App\Domain\Api\Items\ItemQuery;
use App\Domain\Api\Items\ItemShapes;
use App\Domain\Api\Items\ItemsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The API's items (D4, D37): a list of live, public items, one item by its
 * ID, and one by an address of it. Reads only, through the item queries.
 */
final class ItemsController
{
    /**
     * The query parameters each action takes.
     *
     * @var array{index: list<string>, show: list<string>, byPath: list<string>}
     */
    public const array PARAMETERS = [
        'index' => ['type', 'term', 'author', 'published_since', 'published_before', 'parent', 'language', 'sort', 'limit', 'after'],
        'show' => [],
        'byPath' => ['path'],
    ];

    /** The longest address `path` may give. */
    private const int LONGEST_PATH = 2048;

    public function index(Request $request, ItemsQuery $items, ItemShapes $shapes): JsonResponse
    {
        $query = ApiQuery::from($request, self::PARAMETERS['index']);
        $filters = ItemFilters::from($query);
        $page = $items->page($filters);
        $parts = $shapes->parts($page['rows']);

        return ApiResponse::list(
            array_map(fn (object $row): array => $shapes->summary($row, $parts), $page['rows']),
            $filters->limit,
            Envelope::link('api.v1.items.index', [], $query->values),
            $page['next'] === null ? null : Envelope::link('api.v1.items.index', [], ['after' => $page['next']] + $query->values),
        );
    }

    public function show(Request $request, string $id, ItemQuery $item): JsonResponse
    {
        ApiQuery::from($request, self::PARAMETERS['show']);
        $row = $item->byId($id) ?? throw ApiNotFound::item($id);

        return ApiResponse::one($item->full($row), ['self' => Envelope::link('api.v1.items.show', ['id' => $id])]);
    }

    public function byPath(Request $request, ItemQuery $item): JsonResponse
    {
        $path = ApiQuery::from($request, self::PARAMETERS['byPath'])->get('path');

        // Decoded once, by ApiQuery: a control character, a NUL above all, is never part of an address.
        if ($path === null || ! str_starts_with($path, '/') || strpbrk($path, '?#') !== false || preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || mb_strlen($path) > self::LONGEST_PATH) {
            throw new InvalidParameters(['path' => ApiWords::badPath()]);
        }

        $found = $item->byPath($path) ?? throw ApiNotFound::path($path);
        $id = (string) $found['row']->id;

        return ApiResponse::one(
            $item->full($found['row']),
            ['self' => Envelope::link('api.v1.items.show', ['id' => $id])],
            ['requested_path' => $path, 'moved' => $found['moved']],
        );
    }
}
