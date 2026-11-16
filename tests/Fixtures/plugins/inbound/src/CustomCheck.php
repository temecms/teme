<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Inbound;

use Teme\Contracts\Routes\InboundVerifier;
use Teme\Contracts\Routes\PublicRequest;

/**
 * Accepts a POST when the X-Custom header equals the secret.
 */
final class CustomCheck implements InboundVerifier
{
    public function verify(PublicRequest $request, string $secret): bool
    {
        return hash_equals($secret, $request->headers['x-custom'] ?? '');
    }
}
