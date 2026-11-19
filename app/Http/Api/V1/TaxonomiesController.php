<?php

/**
 * GET /api/v1/taxonomies: the public taxonomies and the public types using
 * them (D28).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Api\ApiQuery;
use App\Domain\Api\Catalogue\TaxonomiesShape;
use App\Domain\Api\Problems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaxonomiesController
{
    /** The parameters this address takes: none. */
    public const array PARAMETERS = [];

    public function __invoke(Request $request, TaxonomiesShape $taxonomies): JsonResponse
    {
        ApiQuery::from($request, self::PARAMETERS);

        // A whole list with no paging: `data` and `links` only, which ApiResponse::list() (with its meta and next) doesn't give.
        return new JsonResponse(['data' => $taxonomies->data(), 'links' => $taxonomies->links()], 200, ['Content-Type' => 'application/json'], Problems::JSON_FLAGS);
    }
}
