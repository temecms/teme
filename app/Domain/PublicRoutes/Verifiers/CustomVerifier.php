<?php

/**
 * Instantiates the plugin's InboundVerifier class and asks it.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\PublicRoutes\Verifiers;

use Teme\Contracts\Routes\InboundVerifier;
use Teme\Contracts\Routes\PublicRequest;

final class CustomVerifier
{
    /**
     * @param  array{kind: string, class?: string, secret?: string}  $verify
     */
    public function passes(PublicRequest $request, array $verify, string $secret): bool
    {
        $class = ltrim((string) ($verify['class'] ?? ''), '\\');

        if ($class === '' || ! class_exists($class)) {
            return false;
        }

        $instance = new $class;

        return $instance instanceof InboundVerifier && $instance->verify($request, $secret);
    }
}
