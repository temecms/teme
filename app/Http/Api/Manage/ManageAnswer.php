<?php

declare(strict_types=1);

namespace App\Http\Api\Manage;

use App\Domain\Api\Problems;
use Illuminate\Http\JsonResponse;

/**
 * A management API answer: its data in the delivery API's envelope, encoded
 * as every API answer is.
 */
final class ManageAnswer
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, string>  $links
     */
    public static function data(array $data, int $status = 200, array $links = []): JsonResponse
    {
        $body = $links === [] ? ['data' => $data] : ['data' => $data, 'links' => $links];

        return new JsonResponse($body, $status, ['Content-Type' => 'application/json'], Problems::JSON_FLAGS);
    }
}
