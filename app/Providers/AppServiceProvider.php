<?php

/**
 * Registers the content policy and core's site search, and sets
 * application-wide defaults: immutable dates, and a ban on destructive
 * database commands in production.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Api\ApiPaths;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Policies\ContentPolicy;
use App\Domain\Deployment\DestructiveCommands;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Domain\Search\PostgresSiteSearch;
use App\Domain\Status\StatusChecks;
use App\Domain\Users\Actors;
use App\Http\Standalone\ErrorReference;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Teme\Contracts\Search\SiteSearch;

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
        // One reference for each request's failure, the log's and the 500 page's.
        $this->app->scoped(ErrorReference::class);

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

        // A search overlay asks as someone types; a page's stored answers aren't counted, so this
        // bounds only what is drawn, per address.
        RateLimiter::for('suggest', fn (Request $request): Limit => Limit::perMinute(60)->by('suggest:'.$request->ip()));

        // A width region's content is asked for once per page view, by core's script; it has its own
        // tally, so a search overlay's typing never uses up a region's budget, nor the reverse.
        RateLimiter::for('region-fragment', fn (Request $request): Limit => Limit::perMinute(60)->by('region-fragment:'.$request->ip()));

        // The command palette searches as someone types, debounced; sixty a
        // minute is far more than a person types, and bounds a script.
        RateLimiter::for('admin-search', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->user()?->getAuthIdentifier()));

        // A plugin's panel in the editor: its actions and the person's use of
        // its suggestions each run plugin code; sixty a minute per person is
        // far more than anyone clicks, and bounds a script.
        RateLimiter::for('editor-panels', fn (Request $request): Limit => Limit::perMinute(60)->by('editor-panels:'.(string) $request->user()?->getAuthIdentifier()));

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

        // Declared public routes: per entry and address; 120 GET / 30 POST
        // a minute unless the entry asks for less, never more than 240 / 60.
        RateLimiter::for('declared-routes', function (Request $request): Limit {
            $route = app(PublicRouteRegistry::class)->matchRequest($request);

            // No declared match: the request goes on to the public site's own
            // routes, which count a search as site-search does.
            if ($route === null) {
                return $request->query->has('s')
                    ? Limit::perMinute(30)->by((string) $request->ip())
                    : Limit::none();
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

        DestructiveCommands::guardDefault($this->app->isProduction());
        Event::listen(CommandStarting::class, fn (CommandStarting $event) => DestructiveCommands::starting($event, $this->app->isProduction()));
    }
}
