<?php

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Api\ApiQuery;
use App\Domain\Api\Catalogue\TypesShape;
use App\Domain\Api\Problems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/types: the public content types, their archives, public
 * taxonomies and the fields they show (D28).
 */
final class TypesController
{
    /** The parameters this address takes: none. */
    public const array PARAMETERS = [];

    public function __invoke(Request $request, TypesShape $types): JsonResponse
    {
        ApiQuery::from($request, self::PARAMETERS);

        // A whole list with no paging: `data` and `links` only, which ApiResponse::list() (with its meta and next) doesn't give.
        return new JsonResponse(['data' => $types->data(), 'links' => $types->links()], 200, ['Content-Type' => 'application/json'], Problems::JSON_FLAGS);
    }
}
