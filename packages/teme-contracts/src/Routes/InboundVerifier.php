<?php

/**
 * A plugin's own check for an inbound POST (HTTP Signatures and the like).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Routes;

/**
 * A plugin's own check for an inbound POST (HTTP Signatures and the like).
 *
 * @since 1.0.0
 */
interface InboundVerifier
{
    /**
     * Whether the request is authentic given the named secret's value.
     *
     * @since 1.0.0
     */
    public function verify(PublicRequest $request, string $secret): bool;
}
