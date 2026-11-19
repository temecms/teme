<?php

/**
 * Compares a named header to hash_hmac('sha256', rawBody, secret).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\PublicRoutes\Verifiers;

use Teme\Contracts\Routes\PublicRequest;

final class HmacVerifier
{
    /**
     * @param  array{kind: string, header?: string, secret?: string}  $verify
     */
    public function passes(PublicRequest $request, array $verify, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $header = strtolower((string) ($verify['header'] ?? ''));
        $given = $request->headers[$header] ?? null;

        if (! is_string($given) || $given === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->rawBody, $secret);

        return hash_equals($expected, $given);
    }
}
