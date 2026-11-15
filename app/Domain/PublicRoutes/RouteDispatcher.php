<?php

declare(strict_types=1);

namespace App\Domain\PublicRoutes;

use App\Domain\Cache\RenderRecord;
use App\Domain\Plugins\Faults\SpooledFaults;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\PluginRegistry;
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
 * body cap and POST verifier, catches throws into a 500 with a spool entry,
 * and maps the plain response to Symfony's.
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

    public function dispatch(DeclaredRoute $route, Request $request): SymfonyResponse
    {
        if ($route->owner !== DeclaredRoute::OWNER_CORE) {
            $this->autoloadPlugin($route->owner);
        }

        $rawBody = $request->getContent();

        // A form POST in tests (and some stacks) fills the request bag without
        // leaving a body string; rebuild one so field: verifiers can read it.
        if ($rawBody === '' && $request->isMethod('POST') && $request->request->count() > 0) {
            $rawBody = http_build_query($request->request->all());
        }

        if ($request->isMethod('POST') && strlen($rawBody) > $route->maxBody) {
            return new Response('', 413);
        }

        $public = $this->toPublicRequest($route, $request, $rawBody);

        if ($request->isMethod('POST') && ! $this->verifiers->passes($route, $public)) {
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

            return new Response('', 500);
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
            parameters: $this->pathParameters($route, $request->getPathInfo()),
            query: $query,
            headers: $headers,
            rawBody: $rawBody,
            clientAddress: (string) $request->ip(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function pathParameters(DeclaredRoute $route, string $path): array
    {
        $pattern = $route->resolvedPath();
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $m) use ($route): string {
            $name = $m[1];
            $rule = $route->where[$name] ?? '[^/]+';

            return '(?P<'.$name.'>'.$rule.')';
        }, $pattern);

        if (preg_match('#^'.str_replace('#', '\#', (string) $regex).'$#', $path, $matches) !== 1) {
            return [];
        }

        $parameters = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
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

        $response = new Response($answer->body, $answer->status, $headers);

        if ($route->isCacheable()) {
            $response->headers->set('X-Teme-Declared-Cacheable', '1');
        }

        return $response;
    }
}
