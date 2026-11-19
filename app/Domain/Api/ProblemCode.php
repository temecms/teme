<?php

/**
 * What went wrong with an API request, as clients switch on it: the `code`
 * of a problem, whose meaning never changes within v1 (I-3324).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api;

enum ProblemCode: string
{
    case InvalidParameter = 'invalid_parameter';
    case NotFound = 'not_found';
    case ApiOff = 'api_off';
    case MethodNotAllowed = 'method_not_allowed';
    case RateLimited = 'rate_limited';
    case Unavailable = 'unavailable';
    case ServerError = 'server_error';
    case HttpError = 'http_error';
}
