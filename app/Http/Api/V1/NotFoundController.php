<?php

/**
 * Anything under /api that no route of the API answers: a not_found
 * problem, never the public site, whatever the switch says (I-091).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Api\V1;

use App\Domain\Api\ApiNotFound;
use Illuminate\Http\Request;

final class NotFoundController
{
    public function __invoke(Request $request): never
    {
        throw ApiNotFound::nothing($request->getPathInfo());
    }
}
