<?php

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Contents\ReaderPasswords;
use App\Domain\Site\ItemLanguage;
use App\Domain\Site\PathResolver;
use App\Domain\Site\ReaderKeys;
use App\Domain\Site\ViewModels\ProtectedView;
use App\Domain\Themes\ViewIntent;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The password form's post (I-1700). The right password sends the reader
 * back to the page with a key for it in their cookie; a wrong one draws the
 * form again. No session and no token: a form another site sent is turned
 * away unread, and guessing is what the count of tries limits.
 */
final class UnlockController extends Controller
{
    /** Tries one reader's address may make a minute, and an hour. */
    private const int PER_MINUTE = 10;

    private const int PER_HOUR = 60;

    public function __invoke(Request $request): Response
    {
        $path = is_string($request->input('path')) ? (string) $request->input('path') : '';

        // First, before anything is read or counted: another site's form arrives without the reader's
        // cookie (SameSite=Lax), so its answer would replace the keys they hold, and its tries would
        // use up their address's.
        if ($request->headers->get('Sec-Fetch-Site') === 'cross-site') {
            return self::private(new RedirectResponse(self::here($path, false), 303));
        }

        // Before the page is built, as PublicController starts its own.
        PublicPage::start($request);

        $given = is_string($request->input('password')) ? (string) $request->input('password') : '';
        $answer = mb_strlen($path) <= 2000 && str_starts_with($path, '/') ? PathResolver::resolve($path, '') : null;
        $view = $answer['model'] ?? null;

        if (! $view instanceof ProtectedView || ! isset($answer['item'])) {
            // Not a protected item's address now: back to the site, by its own path.
            return self::private(new RedirectResponse(self::here($path, false), 303));
        }

        // Answered in the item's own language (I-3360).
        ItemLanguage::use((string) $answer['item']);
        $bucket = self::bucket((string) $request->ip());
        $wait = max(RateLimiter::availableIn('unlock:'.$bucket), RateLimiter::availableIn('unlock-hour:'.$bucket));

        if (RateLimiter::tooManyAttempts('unlock:'.$bucket, self::PER_MINUTE) || RateLimiter::tooManyAttempts('unlock-hour:'.$bucket, self::PER_HOUR)) {
            $response = PublicPage::render(new ViewIntent('protected'), $view->withError(__('Too many tries. Wait a minute, then try again.')), 429);
            $response->headers->set('Retry-After', (string) max(1, $wait));

            return self::private($response);
        }

        RateLimiter::hit('unlock:'.$bucket, 60);
        RateLimiter::hit('unlock-hour:'.$bucket, 3600);

        $key = ReaderPasswords::keyFor((string) $answer['item'], $given);

        if ($key === null) {
            return self::private(PublicPage::render(new ViewIntent('protected'), $view->withError(__('That isn’t the password. Check it and try again.')), 422));
        }

        $response = new RedirectResponse(self::here($path, true), 303);
        $response->headers->setCookie(ReaderKeys::from($request)->with($key)->cookie($request->isSecure()));

        return self::private($response);
    }

    /**
     * Whose tries these are: an IPv4 address; an IPv6 address's /64, within
     * which one reader's device may change its address at will; an IPv4
     * address carried in IPv6 as that IPv4 address.
     */
    private static function bucket(string $ip): string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);

        if ($packed === false || strlen($packed) === 4) {
            return $ip;
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return bin2hex(substr($packed, 0, 8)).'/64';
    }

    /**
     * This site's address for the path, always built from its root, so no
     * answer here leads off the site. A protected item's own address is the
     * one the resolver just found canonical, so it goes back as it came;
     * anything else goes by the path table's form, and the site's own
     * canonical rule takes it from there. Not redirect(): the URL generator
     * trims the trailing slash (docs/architecture/addresses.md).
     */
    private static function here(string $path, bool $canonical): string
    {
        $root = rtrim(url('/'), '/');

        if ($canonical) {
            return $root.PublicRequestPath::encode(rawurldecode($path));
        }

        $normal = PublicRequestPath::normalise($path);

        return $normal === '' ? $root.'/' : $root.PublicRequestPath::encode('/'.$normal);
    }

    private static function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
