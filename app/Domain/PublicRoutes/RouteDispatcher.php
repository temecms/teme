<?php

declare(strict_types=1);

namespace App\Domain\PublicRoutes;

use App\Domain\Cache\RenderRecord;
use App\Domain\Plugins\Faults\SpooledFaults;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\PluginRegistry;
use Closure;
use Composer\Autoload\ClassLoader;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;
use Throwable;

/**
 * Runs a declared route's handler: builds the plain request, enforces the
 * body cap and POST verifier, records a throw against its owner and answers
 * with the site's error page, and maps the plain response to Symfony's.
 */
final class RouteDispatcher
{
    public const int HANDLER_SECONDS = 30;

    /** @var array<string, true> plugin codes whose Composer autoload was registered on this instance */
    private array $autoloaded = [];

    public function __construct(
        private readonly SpooledFaults $faults,
        private readonly Verifiers\RouteVerifier $verifiers,
        private readonly PluginRegistry $plugins,
    ) {}

    /**
     * The entry's answer to this request. A handler that throws is recorded
     * against its owner and answered by $failed, the site's own error page,
     * with nothing of the plugin's shown (docs/architecture/plugins.md).
     *
     * @param  Closure(): SymfonyResponse  $failed
     */
    public function dispatch(DeclaredRoute $route, Request $request, Closure $failed): SymfonyResponse
    {
        if ($route->owner !== DeclaredRoute::OWNER_CORE) {
            $this->autoloadPlugin($route->owner);
        }

        $posted = $request->isMethod('POST');

        // The declared length first, so an oversized body is refused before it is read.
        if ($posted && (int) $request->headers->get('Content-Length', '0') > $route->maxBody) {
            return new Response('', 413);
        }

        $rawBody = $posted ? $request->getContent() : '';

        if ($posted && strlen($rawBody) > $route->maxBody) {
            return new Response('', 413);
        }

        $public = $this->toPublicRequest($route, $request, $rawBody);

        if ($posted && ! $this->verifiers->passes($route, $public)) {
            return new Response('', 401);
        }

        $previous = ini_get('max_execution_time');
        set_time_limit(self::HANDLER_SECONDS);

        try {
            $handler = $this->handler($route);
            $answer = $handler->handle($public);

            if ($route->isCacheable() && $route->owner !== DeclaredRoute::OWNER_CORE) {
                app(RenderRecord::class)->tag('plugin:'.$route->owner);
            }
        } catch (Throwable $failure) {
            $this->faults->record(
                $route->owner === DeclaredRoute::OWNER_CORE ? 'core' : 'plugin:'.$route->owner,
                $route->entryKey(),
                '',
                $failure,
            );

            return $failed();
        } finally {
            set_time_limit((int) $previous);
        }

        return $this->toSymfony($answer, $route);
    }

    private function handler(DeclaredRoute $route): PublicRoute
    {
        $class = $route->handler;

        if (! class_exists($class)) {
            throw new RuntimeException("Handler {$class} is missing.");
        }

        $instance = $route->owner === DeclaredRoute::OWNER_CORE
            ? app($class)
            : new $class;

        if (! $instance instanceof PublicRoute) {
            throw new RuntimeException("Handler {$class} does not implement PublicRoute.");
        }

        return $instance;
    }

    /**
     * A plugin in teme.plugin_paths isn't in Composer's autoloader: register
     * its psr-4 once so its handlers can be built without starting the plugin.
     */
    private function autoloadPlugin(string $code): void
    {
        if (isset($this->autoloaded[$code])) {
            return;
        }

        $found = null;

        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->code === $code) {
                $found = $plugin;
                break;
            }
        }

        if ($found instanceof FoundPlugin) {
            $composer = is_file($found->path.'/composer.json')
                ? json_decode((string) file_get_contents($found->path.'/composer.json'), true)
                : null;
            $psr4 = is_array($composer['autoload']['psr-4'] ?? null) ? $composer['autoload']['psr-4'] : [];
            $loader = new ClassLoader;

            foreach ($psr4 as $prefix => $folders) {
                $loader->addPsr4((string) $prefix, array_map(fn (mixed $folder): string => $found->path.'/'.$folder, (array) $folders));
            }

            $loader->register();
        }

        $this->autoloaded[$code] = true;
    }

    private function toPublicRequest(DeclaredRoute $route, Request $request, string $rawBody): PublicRequest
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        $query = [];

        foreach ($request->query->all() as $name => $value) {
            if (is_scalar($value)) {
                $query[(string) $name] = (string) $value;
            }
        }

        return new PublicRequest(
            method: strtoupper($request->getMethod()),
            path: $request->getPathInfo(),
            parameters: $route->pathParameters($request->getPathInfo()) ?? [],
            query: $query,
            headers: $headers,
            rawBody: $rawBody,
            clientAddress: (string) $request->ip(),
        );
    }

    private function toSymfony(PublicResponse $answer, DeclaredRoute $route): SymfonyResponse
    {
        $headers = ['Content-Type' => $answer->contentType];

        foreach ($answer->headers as $name => $value) {
            if (in_array(strtolower($name), PublicResponse::HEADER_ALLOW_LIST, true)) {
                $headers[$name] = $value;
            }
        }

        if ($route->isCacheable()) {
            $lifetime = is_int($route->cache['lifetime'] ?? null) ? (int) $route->cache['lifetime'] : 3600;
            $headers['Cache-Control'] = 'public, max-age=0, s-maxage='.min(60, $lifetime);
        } else {
            $headers['Cache-Control'] = 'no-store, private';
        }

        return new Response($answer->body, $answer->status, $headers);
    }
}
