<?php

/**
 * GET /api/v1: names the site and links to the rest of the API (D28).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Api\ApiQuery;
use App\Domain\Api\ApiResponse;
use App\Domain\Api\Catalogue\SiteShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SiteController
{
    /** The parameters this address takes: none. */
    public const array PARAMETERS = [];

    public function __invoke(Request $request, SiteShape $site): JsonResponse
    {
        ApiQuery::from($request, self::PARAMETERS);

        return ApiResponse::one($site->data(), $site->links());
    }
}
