<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health\Checks;

use App\Domain\Deployment\Health\DoctorCheck;
use App\Domain\Deployment\Health\Finding;
use App\Domain\Deployment\Health\Severity;
use App\Domain\Support\Security\TrustedProxies;
use InvalidArgumentException;

/**
 * Says which proxies Teme believes (I-380): an empty list in production
 * makes every reader look like the proxy, so it is a warning there.
 */
final readonly class TrustedProxyList implements DoctorCheck
{
    public function title(): string
    {
        return 'Trusted proxies';
    }

    public function examine(): array
    {
        $configured = config('app.trusted_proxies');
        $production = app()->isProduction();

        try {
            $proxies = TrustedProxies::resolve(is_string($configured) ? $configured : null, $production);
        } catch (InvalidArgumentException $exception) {
            return [new Finding(Severity::Error, $exception->getMessage())];
        }

        if (! is_string($configured)) {
            return [new Finding(Severity::Ok, $production
                ? 'TRUSTED_PROXIES is unset, so the private ranges a Docker network uses are trusted.'
                : 'TRUSTED_PROXIES is unset, so no proxy is trusted outside production.')];
        }

        if ($proxies === []) {
            return $production
                ? [new Finding(Severity::Warning, 'TRUSTED_PROXIES is empty, so behind a proxy every reader looks like the proxy and every link starts http.', 'Behind Traefik alone, remove TRUSTED_PROXIES; behind Cloudflare and Traefik, write private,cloudflare.')]
                : [new Finding(Severity::Ok, 'TRUSTED_PROXIES is empty, so no proxy is trusted.')];
        }

        $count = count($proxies);

        return [new Finding(Severity::Ok, "TRUSTED_PROXIES is {$configured}: ".($count === 1 ? '1 address or range is trusted.' : "{$count} addresses and ranges are trusted."))];
    }
}
