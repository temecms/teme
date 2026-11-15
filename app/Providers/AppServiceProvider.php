<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Api\ApiPaths;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Policies\ContentPolicy;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\Search\PostgresSiteSearch;
use App\Domain\Status\StatusChecks;
use App\Domain\Users\Actors;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Teme\Contracts\Search\SiteSearch;

/**
 * Registers the content policy and core's site search, and sets
 * application-wide defaults: immutable dates, and a ban on destructive
 * database commands in production.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Each area registers its own checks as it boots (I-221).
        $this->app->singleton(StatusChecks::class);

        // The people Operations act as, kept for a scheduler pass or a Bulk
        // Edit batch (B-200, B-201) and empty between them. Scoped, so the
        // queue worker's next job and Octane's next request start with
        // nobody kept, whatever a caller forgot (I-087).
        $this->app->scoped(Actors::class);

        // Core's search; a plugin may bind another engine behind the contract.
        $this->app->bind(SiteSearch::class, PostgresSiteSearch::class);
    }

    public function boot(): void
    {
        Gate::policy(Content::class, ContentPolicy::class);

        $this->configureDefaults();

        // A preview link is the one door into unpublished content, so its
        // token cannot be guessed at speed.
        RateLimiter::for('preview', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));

        // Public search is the dearest thing an anonymous reader can ask for
        // (1.6h). Only a search counts; the public group has no session, so
        // the cache store keeps the tally.
        RateLimiter::for('site-search', fn (Request $request): Limit => $request->query->has('s')
            ? Limit::perMinute(30)->by((string) $request->ip())
            : Limit::none());

        // The command palette searches as someone types, debounced; sixty a
        // minute is far more than a person types, and bounds a script.
        RateLimiter::for('admin-search', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->user()?->getAuthIdentifier()));

        // The API's drawn answers, per address; the page cache's stored ones
        // are never counted (I-3324).
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(ApiPaths::PER_MINUTE)->by('api:'.$request->ip()));

        // Policy reports (I-3375): anyone may send them, so each address sends
        // ten a minute and the whole site six hundred; past either, a report is
        // dropped and still answered 204, so a sender learns nothing.
        RateLimiter::for('csp-reports', fn (Request $request): array => [
            Limit::perMinute(10)->by('csp:'.$request->ip())->response(fn (): Response => response()->noContent()),
            Limit::perMinute(600)->by('csp:site')->response(fn (): Response => response()->noContent()),
        ]);

        // Declared public routes (D6): per entry and address; 120 GET / 30 POST
        // a minute unless the entry asks for less, never more than 240 / 60.
        RateLimiter::for('declared-routes', function (Request $request): Limit {
            $query = [];

            foreach ($request->query->all() as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $query[$name] = $value;
                }
            }

            $accept = [];

            foreach (explode(',', (string) $request->headers->get('Accept', '')) as $part) {
                $type = strtolower(trim(explode(';', $part)[0]));

                if ($type !== '' && $type !== '*/*') {
                    $accept[] = $type;
                }
            }

            $route = app(PublicRouteRegistry::class)->match($request->getMethod(), $request->getPathInfo(), $query, $accept);

            if ($route === null) {
                return Limit::none();
            }

            $default = $route->method === 'POST' ? 30 : 120;
            $cap = $route->method === 'POST' ? 60 : 240;
            $max = $route->throttle === null ? $default : min($cap, max(1, $route->throttle));

            return Limit::perMinute($max)->by('declared:'.$route->entryKey().':'.$request->ip());
        });
    }

    private function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
