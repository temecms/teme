<?php

/**
 * Picks the POST verifier a declared route named and runs it with the
 * plugin's secret setting, or with '' when a custom verifier names none.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\PublicRoutes\Verifiers;

use App\Domain\PublicRoutes\DeclaredRoute;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Settings\SecretReader;

final readonly class RouteVerifier
{
    public function __construct(
        private SecretReader $secrets,
        private SecretVerifier $secret,
        private HmacVerifier $hmac,
        private CustomVerifier $custom,
    ) {}

    public function passes(DeclaredRoute $route, PublicRequest $request): bool
    {
        $verify = $route->verify;

        if ($verify === null) {
            return false;
        }

        $kind = (string) $verify['kind'];
        $secretName = (string) ($verify['secret'] ?? '');
        // A custom verifier may name no secret: it is given '', and the store isn't read (docs/guides/developers/plugins/public-routes.md).
        $secret = $route->owner === DeclaredRoute::OWNER_CORE || $secretName === ''
            ? ''
            : (string) ($this->secrets->secret('plugin.'.$route->owner, $secretName) ?? '');

        return match ($kind) {
            'secret' => $this->secret->passes($request, $verify, $secret),
            'hmac' => $this->hmac->passes($request, $verify, $secret),
            'custom' => $this->custom->passes($request, $verify, $secret),
            default => false,
        };
    }
}
