<?php

declare(strict_types=1);

namespace App\Http\Site\Middleware;

use App\Domain\Cache\SignedInMarker;
use App\Domain\Site\CommentViews;
use App\Http\Admin\Middleware\EnforceSessionLifetime;
use App\Http\Admin\Middleware\EnsureAccountIsActive;
use App\Http\Site\CommentAnswers;
use App\Http\Site\CommentPost;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * The comment form's forgery rule (1.8b's D7), since Laravel's compares
 * tokens only in a session, and a guest who comments has none. A form another
 * site sent is refused, by its Fetch Metadata or, without that, its Origin. A
 * browser with no sign-in marker then posts as a guest, with no session read:
 * it carries no identity to forge. A marked one has its session read for this
 * request alone, and, signed in, must say the form is its own or send the
 * session's token, as PreventRequestForgery::handle() asks. Its refusals
 * answer core's script in JSON when it asks (1.8b′).
 */
final readonly class GuardCommentPost
{
    /** The reader group's session middleware, after its first (bootstrap/app.php). */
    private const array SESSION = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        EnforceSessionLifetime::class,
        AuthenticateSession::class,
        EnsureAccountIsActive::class,
    ];

    public function __construct(private Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->headers->get('Sec-Fetch-Site');

        // Only this site's own page may post: same-site is refused too, as Laravel refuses it.
        if (($site !== null && $site !== 'same-origin') || ($site === null && ! self::ownOrigin($request))) {
            return self::refuse($request, __('This form came from another site, so your comment wasn’t posted. To comment, go back to the story and use the form there.'));
        }

        if (! SignedInMarker::present($request)) {
            return $next($request);
        }

        return (new Pipeline($this->container))
            ->send($request)
            ->through(self::SESSION)
            ->then(function (Request $request) use ($next, $site): mixed {
                if ($request->user() !== null && $site !== 'same-origin' && ! self::tokenMatches($request)) {
                    return self::refuse($request, __('Your browser didn’t say this form came from this site. Turn on JavaScript, or use a newer browser, and try again.'));
                }

                return $next($request);
            });
    }

    /**
     * Whether the Origin sent, if any, is this site's: APP_URL's scheme, host
     * and port, or this request's own. None at all passes: a browser too old to
     * send Fetch Metadata may send no Origin with a same-origin form either.
     */
    private static function ownOrigin(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null) {
            return true;
        }

        $app = parse_url((string) config('app.url'));
        $own = [$request->getSchemeAndHttpHost()];

        if (is_array($app) && isset($app['scheme'], $app['host'])) {
            $own[] = $app['scheme'].'://'.$app['host'].(isset($app['port']) ? ':'.$app['port'] : '');
        }

        return in_array(strtolower($origin), array_map(strtolower(...), $own), true);
    }

    /**
     * Whether the form carries the session's token, compared as Laravel compares them.
     */
    private static function tokenMatches(Request $request): bool
    {
        $sent = $request->input('_token');
        $held = $request->session()->get('_token');

        return is_string($sent) && $sent !== '' && is_string($held) && hash_equals($held, $sent);
    }

    /**
     * The "wasn't posted" answer, the reader's words kept (I-593), 403: a
     * page, or core's script's JSON when it asks.
     */
    private static function refuse(Request $request, string $reason): Response
    {
        $post = CommentPost::from($request);

        return CommentAnswers::for($request)->refused(app(CommentViews::class)->target($post->path), $reason, 403);
    }
}
