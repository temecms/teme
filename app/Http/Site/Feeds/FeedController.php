<?php

declare(strict_types=1);

namespace App\Http\Site\Feeds;

use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Feeds\FeedAnswer;
use App\Domain\Feeds\FeedBuilder;
use App\Domain\Feeds\FeedFormat;
use App\Domain\Feeds\FeedResolver;
use App\Domain\Feeds\FeedSubject;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Http\Controllers\Controller;
use App\Http\Site\PublicController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Every public address that ends in a feed name (I-299): the feed of the
 * site, a term or an author as XML in the bound format, a permanent redirect
 * to the canonical spelling, or 410 for a comment feed. Anything that
 * is not a feed's goes back to PublicController, unchanged, so redirects and
 * the 404 log still see it. Marked cacheable by its route.
 */
final class FeedController extends Controller
{
    public function __construct(
        private readonly FeedResolver $resolver,
        private readonly FeedBuilder $builder,
        private readonly FeedFormat $format,
        private readonly PublicController $public,
        private readonly PageLanguage $languages,
        private readonly SiteLanguages $siteLanguages,
    ) {}

    public function __invoke(Request $request): BaseResponse
    {
        $answer = $this->resolver->resolve($request->getPathInfo());

        return match ($answer->kind) {
            FeedAnswer::FEED => $this->feed($answer->subject ?? FeedSubject::site()),
            FeedAnswer::REDIRECT => new RedirectResponse(rtrim(url('/'), '/').PublicRequestPath::encode((string) $answer->location), 301),
            FeedAnswer::GONE => new Response("Gone. This site has no feed of comments.\n", 410, ['Content-Type' => 'text/plain; charset=UTF-8']),
            default => $this->public->handle($request),
        };
    }

    private function feed(FeedSubject $subject): BaseResponse
    {
        // The feed's words and dates in its own language (I-3360).
        $this->languages->use($this->siteLanguages->of($subject->language));
        $feed = $this->builder->build($subject);

        return new Response($this->format->render($feed), 200, ['Content-Type' => $this->format->contentType(), 'X-Robots-Tag' => $feed->robots]);
    }
}
