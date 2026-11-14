<?php

declare(strict_types=1);

namespace Tests\Fixtures\Deployment;

use App\Providers\DeploymentServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * The edge as a server meets it: production at an address, the proxies
 * trusted as boot trusts them, the requests Traefik sends, and a route
 * that says what Teme made of one.
 */
final class Edge
{
    /** Traefik's address on a Docker network. */
    public const string TRAEFIK = '172.18.0.5';

    /** One of Cloudflare's, inside 162.158.0.0/15. */
    public const string CLOUDFLARE = '162.158.1.1';

    public const string READER = '203.0.113.7';

    public const string OTHER_READER = '198.51.100.23';

    /**
     * The application as production at $url, for this test.
     */
    public static function production(string $url = 'https://example.com'): void
    {
        app()->instance('env', 'production');
        config(['app.url' => $url]);
    }

    /**
     * TRUSTED_PROXIES as $configured (null: unset), applied as boot applies it.
     */
    public static function trustProxies(?string $configured): void
    {
        config(['app.trusted_proxies' => $configured]);
        $provider = app()->getProvider(DeploymentServiceProvider::class);

        if (! $provider instanceof DeploymentServiceProvider) {
            throw new RuntimeException('DeploymentServiceProvider is not registered.');
        }

        $provider->trustProxies();
    }

    /**
     * GET /_teme/edge-probe: the address, the scheme and the root Teme read
     * from the request. Under _teme, so the page cache passes it by.
     */
    public static function probe(): void
    {
        Route::get('/_teme/edge-probe', fn (Request $request): array => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'root' => $request->root(),
        ]);
    }

    /**
     * What Traefik sends the web container for a request it took over
     * https: its own address as the connection's; X-Forwarded-For as the
     * chain it was handed, then the address it took the request from; and
     * the scheme, host and port it was asked for.
     *
     * @return array<string, string>
     */
    public static function viaTraefik(string $forwardedFor, string $host = 'example.com'): array
    {
        return [
            'REMOTE_ADDR' => self::TRAEFIK,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => $host,
            'HTTP_X_FORWARDED_PORT' => '443',
        ];
    }

    /**
     * A production site's configuration as the image sets it, which starts;
     * each test changes what it is about, in dotted keys.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function startableConfig(array $changes = []): Repository
    {
        $config = new Repository([
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'url' => 'https://example.com'],
            'session' => ['driver' => 'database', 'secure' => true, 'cookie' => '__Host-teme-session', 'domain' => null, 'path' => '/'],
            'teme' => ['deployment' => ['hsts' => 'max-age=31536000']],
        ]);

        $config->set($changes);

        return $config;
    }
}
