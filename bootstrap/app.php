<?php

declare(strict_types=1);

use App\Domain\Api\ApiPaths;
use App\Domain\Api\Problems;
use App\Domain\Deployment\SecretFiles;
use App\Domain\Plugins\PackagesWithoutPlugins;
use App\Domain\Site\Policy\PolicyReports;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Http\Admin\Middleware\AdminSecurityHeaders;
use App\Http\Admin\Middleware\ApplyRememberPolicy;
use App\Http\Admin\Middleware\BindSessionElevation;
use App\Http\Admin\Middleware\EnforceResponseTimeFloor;
use App\Http\Admin\Middleware\EnforceSessionLifetime;
use App\Http\Admin\Middleware\EnsureAccountIsActive;
use App\Http\Admin\Middleware\RequireTwoFactorEnrolment;
use App\Http\Admin\Middleware\UseAdminLanguage;
use App\Http\Api\Middleware\AnswerAsApi;
use App\Http\Api\Middleware\AnswerNotModified;
use App\Http\Middleware\AnswerVersionProbe;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HoldUntilSetUp;
use App\Http\Middleware\NoindexOutsideProduction;
use App\Http\Middleware\StrictTransportSecurity;
use App\Http\Middleware\TrustSiteHost;
use App\Http\Site\Middleware\AnswerAnonymousReaders;
use App\Http\Site\Middleware\CachePublicPages;
use App\Http\Site\Middleware\DesignPreviewLink;
use App\Http\Site\Middleware\MarkCacheable;
use App\Http\Site\PublicPage;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The API before the public site: /api is the site's own (I-091), and
        // its catch-all answers there before any fallback of routes/public.php.
        // The public site last, so its fallback sees only what no other route matched.
        then: function (): void {
            Route::middleware('api')->group(base_path('routes/api.php'));
            Route::group([], base_path('routes/public.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // A document's text keeps its own spaces: the space before a bold or
        // linked word lives at the edge of the text node beside it (I-452).
        $middleware->trimStrings(except: ['document.*', fn (Request $request): bool => $request->is(ltrim(PolicyReports::PATH, '/'))]);

        // A policy report is a stranger's body, read for the first time after its size is checked
        // (PolicyReportController); these two would decode the whole of a JSON body before that.
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => $request->is(ltrim(PolicyReports::PATH, '/'))]);

        // The admin's middleware only. The public site (slice 1.6) never runs
        // Inertia, which keeps its responses cacheable and its CSP separate.
        // The public site's: no StartSession, EncryptCookies,
        // AddQueuedCookiesToResponse, ShareErrorsFromSession or
        // VerifyCsrfToken, so its responses set no cookie and can be cached.
        $middleware->group('public', [
            // First of all, before any theme or design value: until the site's first account exists, the holding page (issue 246).
            HoldUntilSetUp::class,
            // First after the hold, so the whole request sees one set of design values.
            DesignPreviewLink::class,
            SubstituteBindings::class,
        ]);

        // The API's: no session, cookies or CSRF, as the public site's, so its
        // answers are the same for everyone and the page cache may store them.
        $middleware->group('api', [
            AnswerAsApi::class,
            // Until the site's first account exists, a 503 problem (issue 246).
            HoldUntilSetUp::class,
            SubstituteBindings::class,
            MarkCacheable::class,
        ]);

        $middleware->group('admin', [
            EnforceResponseTimeFloor::class,
            ApplyRememberPolicy::class,
            BindSessionElevation::class,
            AdminSecurityHeaders::class,
            EnforceSessionLifetime::class,
            AuthenticateSession::class,
            EnsureAccountIsActive::class,
            RequireTwoFactorEnrolment::class,
            HandleAppearance::class,
            UseAdminLanguage::class,
            HandleInertiaRequests::class,
        ]);

        // /_teme/session's (I-163): a browser that carries no signed-in
        // marker is answered by the first, with no session started and no
        // cookie set; only a marked browser reaches the session, and the
        // admin's own checks on it. Laravel's sort moves only the middleware
        // in its priority list among themselves, so the first stays first.
        $middleware->group('reader', [
            AnswerAnonymousReaders::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            EnforceSessionLifetime::class,
            AuthenticateSession::class,
            EnsureAccountIsActive::class,
        ]);

        // APP_URL's host alone, and localhost for a health check made inside
        // the container (I-380), in Laravel's place for it: before
        // TrustProxies, which reads the host first.
        $middleware->trustHosts();
        $middleware->replace(TrustHosts::class, TrustSiteHost::class);

        // Outermost, so stored pages, maintenance mode's answer and errors
        // carry it too (I-380).
        $middleware->prepend(StrictTransportSecurity::class);

        // Last, so it sees the global stack as configured above. The page
        // cache joins it after the proxies are trusted, so a page's key has
        // the scheme and host the reader asked for, and before maintenance
        // mode, so readers keep stored pages during a restore
        // (docs/studies/deployment.md §3.9). Laravel's own all stay.
        // use() pins the list, so anything else that changes the global
        // stack, such as the host check above, goes above this.
        $global = $middleware->getGlobalMiddleware();
        // Right inside the page cache, so a page stored outside production
        // keeps the header and never becomes a static file (I-537); maintenance
        // mode's answer, further in, carries it too. The 304 check just outside
        // the page cache, so a stored answer gets its 304 too.
        array_splice($global, (int) array_search(PreventRequestsDuringMaintenance::class, $global, true), 0, [AnswerNotModified::class, CachePublicPages::class, NoindexOutsideProduction::class]);
        // The updater’s probe of /up (I-3326): the version, to a signed ask alone.
        $global[] = AnswerVersionProbe::class;
        $middleware->use($global);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A refused save flashes its input back to the form, and sessions are
        // stored unencrypted, so a typed secret must not be among it. Laravel's
        // own three stay.
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'code', 'secrets', 'clear_secrets', 'recovery_key', 'groups', 'access_key_id', 'secret_access_key', 'private_key', 'credential']);

        // Every error under /api is a problem (RFC 9457), never the theme's page
        // or Laravel's JSON; nothing an exception says reaches it (I-091).
        $exceptions->render(fn (Throwable $exception, Request $request) => ApiPaths::is($request->getPathInfo())
            ? Problems::response($exception, $request)
            : null);

        // Return to the page the form was on, not to the form's own URL,
        // which may only accept PUT. docs/architecture/security.md.
        $exceptions->render(function (ElevationRequiredException $exception, Request $request) {
            // A form that asks for the password in place says so with this
            // header, and keeps what was typed (the invite dialog).
            if ($request->expectsJson() || $request->hasHeader('X-Teme-Ask-In-Place')) {
                return response()->json(['message' => __('Please confirm your password first.')], 423);
            }

            $request->session()->put('url.intended', url()->previous());

            return redirect()->route('password.confirm');
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->expectsJson(),
        );

        // A public page's HTTP error renders through the theme's error
        // templates, or core's when the theme's fails (I-249). Admin and API
        // errors are left as they are.
        $exceptions->render(fn (HttpExceptionInterface $exception, Request $request) => in_array('public', $request->route()?->gatherMiddleware() ?? [], true) && ! $request->expectsJson()
            ? PublicPage::error($exception)
            : null);
    })->create();

// Secrets from files (*_FILE), read once the environment is loaded and
// before configuration, in every process, whether or not configuration is
// cached: settings read some of them at run time (I-375).
$app->afterLoadingEnvironment(SecretFiles::resolveOrStop(...));

// A plugin reaches Teme only through its Plugin class: Laravel's discovery,
// and package:discover, leave every teme-plugin package out (I-3353).
$app->singleton(PackageManifest::class, fn (): PackagesWithoutPlugins => new PackagesWithoutPlugins(new Filesystem, $app->basePath(), $app->getCachedPackagesPath()));

return $app;
