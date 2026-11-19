<?php

/**
 * Turns anything thrown under /api into a problem (RFC 9457): its code and
 * status from what was thrown, its words from ApiWords, never the
 * exception's own message, SQL, class or path, in debug mode or not
 * (I-091). Sets the API's own headers itself, since a 405, maintenance
 * mode's 503 and anything thrown before the route's middleware never pass
 * through AnswerAsApi.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class Problems
{
    /** The flags every API answer is encoded with. */
    public const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * The problem answer for what was thrown while answering this request.
     */
    public static function response(Throwable $exception, Request $request): JsonResponse
    {
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
        $problem = self::problem($exception, $request->getPathInfo());

        // Only what a client needs from the exception's own headers.
        $kept = array_intersect_key($headers, array_flip(match ($problem->code) {
            ProblemCode::RateLimited => ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],
            ProblemCode::MethodNotAllowed => ['Allow'],
            ProblemCode::Unavailable => ['Retry-After'],
            default => [],
        }));

        return self::answer($problem, $kept);
    }

    /**
     * The problem as an answer, with the API's headers.
     *
     * @param  array<string, mixed>  $headers
     */
    public static function answer(Problem $problem, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($problem->toArray(), $problem->status, $headers, self::JSON_FLAGS);
        $response->headers->set('Content-Type', 'application/problem+json');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /**
     * The problem for what was thrown, at this path.
     */
    public static function problem(Throwable $exception, string $path): Problem
    {
        return match (true) {
            $exception instanceof InvalidParameters => new Problem(400, ProblemCode::InvalidParameter, ApiWords::invalidParameters(), $path, $exception->problems),
            $exception instanceof ApiNotFound => new Problem(404, $exception->problem, $exception->detail, $path),
            $exception instanceof ThrottleRequestsException => new Problem(429, ProblemCode::RateLimited, ApiWords::rateLimited(self::retryAfter($exception)), $path),
            $exception instanceof MethodNotAllowedHttpException => new Problem(405, ProblemCode::MethodNotAllowed, ApiWords::methodNotAllowed(), $path),
            // Laravel has already made a missing model one of these.
            $exception instanceof NotFoundHttpException => new Problem(404, ProblemCode::NotFound, ApiWords::nothingAt($path), $path),
            $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 503 => new Problem(503, ProblemCode::Unavailable, ApiWords::unavailable(), $path),
            $exception instanceof HttpExceptionInterface => new Problem($exception->getStatusCode(), ProblemCode::HttpError, ApiWords::httpError(), $path),
            default => new Problem(500, ProblemCode::ServerError, ApiWords::serverError(), $path),
        };
    }

    /**
     * The seconds a rate-limited client waits, from the limiter's own header.
     */
    private static function retryAfter(ThrottleRequestsException $exception): int
    {
        $seconds = $exception->getHeaders()['Retry-After'] ?? 60;

        return max(1, (int) (is_array($seconds) ? reset($seconds) : $seconds));
    }
}
