<?php

/**
 * The fediverse in core (I-058): how remote keys are found, the site's
 * public addresses for actors, discovery and inboxes, and its settings.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\Remote\FetchedKeyOwners;
use App\Domain\Fediverse\Signatures\KeyOwners;
use App\Domain\Fediverse\SiteActors;
use App\Domain\PublicRoutes\DeclaredRoute;
use App\Domain\PublicRoutes\PublicRouteRegistry;
use App\Http\Site\Fediverse\BrowserRoute;
use App\Http\Site\Fediverse\DocumentRoute;
use App\Http\Site\Fediverse\InboxController;
use App\Http\Site\Fediverse\NodeInfoRoute;
use App\Http\Site\Fediverse\WebFingerRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SettingsRegistrar;

final class FediverseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KeyOwners::class, FetchedKeyOwners::class);

        // The registry is scoped, so each new one gets core's entries again.
        $this->app->resolving(PublicRouteRegistry::class, function (PublicRouteRegistry $registry): void {
            foreach (self::routes(SiteActors::exist()) as $route) {
                $registry->registerCore($route);
            }
        });
    }

    public function boot(): void
    {
        // Settings › Fediverse's own group; its switch is modules.fediverse.
        $this->app->make(SettingsRegistrar::class)->group(FediverseSettingsGroup::group());

        if ($this->app->routesAreCached()) {
            return;
        }

        // The inboxes, shared and each actor's, new and imported: their own
        // routes, not declared ones, so a blocked server is refused before the
        // body or the rate limit (docs/plans/fediverse-actors.md, D8).
        Route::middleware('public')->group(function (): void {
            Route::post(ActivityPub::PREFIX.'/{rest}', InboxController::class)->where('rest', '(.+/)?inbox')->name('fediverse.inbox');
            Route::post(ActivityPub::WORDPRESS_PREFIX.'/activitypub/1.0/{rest}', InboxController::class)->where('rest', '(.+/)?inbox')->name('fediverse.wordpress-inbox');
        });
    }

    /**
     * Core's fediverse addresses (docs/plans/fediverse-actors.md, D4): the
     * discovery documents and every /ap/ and WordPress plugin address by
     * path, and, once the site has a fediverse account, the plugin's
     * /?author=N, /?p=N and /@name by their Accept, and a browser at an
     * author's or an item's old address sent on. A site with none leaves
     * those addresses as they were.
     *
     * @return list<DeclaredRoute>
     */
    public static function routes(bool $withAccounts = true): array
    {
        $activity = ['accept' => ActivityPub::ACCEPT];
        $route = fn (string $id, string $path, string $handler, ?array $when = null, array $where = []): DeclaredRoute => new DeclaredRoute(
            owner: DeclaredRoute::OWNER_CORE, id: 'fediverse.'.$id, method: 'GET', path: $path, handler: $handler, when: $when, where: $where,
        );

        $paths = [
            $route('webfinger', '/.well-known/webfinger', WebFingerRoute::class),
            $route('nodeinfo-links', '/.well-known/nodeinfo', NodeInfoRoute::class),
            $route('nodeinfo', '/nodeinfo/2.1', NodeInfoRoute::class),
            $route('documents', '/'.ActivityPub::PREFIX.'/{rest}', DocumentRoute::class, where: ['rest' => '.+']),
            $route('wordpress', '/'.ActivityPub::WORDPRESS_PREFIX.'/activitypub/1.0/{rest}', DocumentRoute::class, where: ['rest' => '.+']),
        ];

        if (! $withAccounts) {
            return $paths;
        }

        return [
            ...$paths,
            $route('author', '/', DocumentRoute::class, ['query' => ['author' => '^[0-9]+$'], ...$activity]),
            $route('post', '/', DocumentRoute::class, ['query' => ['p' => '^[0-9]+$'], ...$activity]),
            $route('at-name', '/@{name}', DocumentRoute::class, $activity, ['name' => '[^/]+']),
            // The blog's /?author=0 stays the home page in a browser.
            $route('author-page', '/', BrowserRoute::class, ['query' => ['author' => '^[1-9][0-9]*$']]),
            $route('post-page', '/', BrowserRoute::class, ['query' => ['p' => '^[1-9][0-9]*$']]),
        ];
    }
}
