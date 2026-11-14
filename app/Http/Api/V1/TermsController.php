<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Api\ApiNotFound;
use App\Domain\Api\ApiQuery;
use App\Domain\Api\ApiResponse;
use App\Domain\Api\Catalogue\SiteShape;
use App\Domain\Api\Catalogue\TermFilters;
use App\Domain\Api\Catalogue\TermsQuery;
use App\Domain\Api\Envelope;
use App\Domain\Api\Shapes\TermShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/terms and /terms/{id}: the terms of the public taxonomies,
 * each with its count of published public items (D27).
 */
final class TermsController
{
    /** The parameters the list takes. */
    public const array PARAMETERS = ['taxonomy', 'parent', 'slug', 'limit', 'after'];

    public function index(Request $request, TermsQuery $terms): JsonResponse
    {
        $query = ApiQuery::from($request, self::PARAMETERS);
        $filters = TermFilters::from($query);
        $page = $terms->page($filters);

        return ApiResponse::list(
            array_map($this->term(...), $page['rows']),
            $filters->limit,
            Envelope::link('api.v1.terms.index', [], $query->values),
            $page['next'] === null ? null : Envelope::link('api.v1.terms.index', [], [...$query->values, 'after' => $page['next']]),
        );
    }

    public function show(Request $request, string $id, TermsQuery $terms): JsonResponse
    {
        ApiQuery::from($request, []);

        $row = $terms->byId($id) ?? throw ApiNotFound::term($id);

        return ApiResponse::one($this->term($row), ['self' => Envelope::link('api.v1.terms.show', ['id' => $id])]);
    }

    /**
     * A term as D27 gives it, with its links.
     *
     * @return array<string, mixed>
     */
    private function term(object $row): array
    {
        $id = (string) $row->id;

        return [
            ...TermShape::full($row, $row->path === null ? null : (string) $row->path, (int) $row->count),
            'links' => ['self' => Envelope::link('api.v1.terms.show', ['id' => $id]), 'items' => SiteShape::itemsLink(['term' => $id])],
        ];
    }
}
