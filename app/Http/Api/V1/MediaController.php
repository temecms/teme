<?php

/**
 * GET /api/v1/media/{id}: a media file, while a live, public, unprotected
 * item shows it (D26).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Api\ApiNotFound;
use App\Domain\Api\ApiQuery;
use App\Domain\Api\ApiResponse;
use App\Domain\Api\Catalogue\MediaQuery;
use App\Domain\Api\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MediaController
{
    /** The parameters this address takes: none. */
    public const array PARAMETERS = [];

    public function __invoke(Request $request, string $id, MediaQuery $media): JsonResponse
    {
        ApiQuery::from($request, self::PARAMETERS);

        $file = $media->shown($id) ?? throw ApiNotFound::file($id);

        return ApiResponse::one($file, ['self' => Envelope::link('api.v1.media.show', ['id' => $id])]);
    }
}
