<?php

declare(strict_types=1);

namespace App\Domain\PublicRoutes\Verifiers;

use App\Domain\PublicRoutes\DeclaredRoute;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Settings\SecretReader;

/**
 * Picks the POST verifier a declared route named and runs it with the
 * plugin's secret setting.
 */
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
        $secret = $route->owner === DeclaredRoute::OWNER_CORE
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
