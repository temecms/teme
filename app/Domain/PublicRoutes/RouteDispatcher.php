<?php

/**
 * Runs a declared route's handler: builds it with its plugin's doors, builds
 * the plain request, enforces the body cap and POST verifier, records a throw against its owner and answers
 * with the site's error page, and maps the plain response to Symfony's.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\PublicRoutes;

use App\Domain\Cache\RenderRecord;
use App\Domain\Plugins\Consent;
use App\Domain\Plugins\Faults\SpooledFaults;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Seams\PluginServices;
use Closure;
use Composer\Autoload\ClassLoader;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;
use Throwable;

final class RouteDispatcher
{
    public const int HANDLER_SECONDS = 30;

    /** @var array<string, true> plugin codes whose Composer autoload was registered on this instance */
    private array $autoloaded = [];

    public function __construct(
        private readonly SpooledFaults $faults,
        private readonly Verifiers\RouteVerifier $verifiers,
        private readonly PluginRegistry $plugins,
        private readonly PluginRecords $records,
        private readonly PluginServices $services,
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
            : $this->built($class, $route->owner);

        if (! $instance instanceof PublicRoute) {
            throw new RuntimeException("Handler {$class} does not implement PublicRoute.");
        }

        return $instance;
    }

    /**
     * A plugin's handler, built as its plugin's class is: with what Teme
     * gives the plugin for each type its constructor asks for, by its
     * consent, never through the container (docs/plans/plugin-sockets-1.md, A-W13).
     *
     * @param  class-string  $class
     */
    private function built(string $class, string $code): object
    {
        $reflection = new ReflectionClass($class);
        $parameters = $reflection->getConstructor()?->getParameters() ?? [];

        // A handler that asks for nothing reads no consent.
        if ($parameters === []) {
            return $reflection->newInstance();
        }

        $plugin = $this->found($code);
        $record = null;

        foreach ($this->records->enabled() as $enabled) {
            if ($plugin !== null && $enabled->name === $plugin->name) {
                $record = $enabled;
            }
        }

        if ($plugin?->manifest === null || $record === null) {
            throw new RuntimeException("Handler {$class} has no enabled plugin to be built for.");
        }

        $services = $this->services->for($plugin->name, $plugin->code, $record->consent['capabilities'] ?? [], Consent::hosts($record->consent, $plugin->manifest));
        $arguments = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

            // The validator refuses such a handler; this guards a cache from before.
            $arguments[] = $services[$name] ?? throw new RuntimeException("Handler {$class} asks for {$name}, which its plugin isn’t given.");
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function found(string $code): ?FoundPlugin
    {
        foreach ($this->plugins->all() as $plugin) {
            if ($plugin->code === $code) {
                return $plugin;
            }
        }

        return null;
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

        $found = $this->found($code);

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
