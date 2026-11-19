<?php

/**
 * What the public site answers for a path (spec §5.9): the canonical form
 * of an address, an old address's redirect, a type's archive page, or a
 * page, with the ViewIntent
 * its template is chosen by and the view model it draws, built by
 * ArticleViews, ListingViews, LatestViews and SearchViews, and a 404 in
 * place for anything else. It reads only, so PublicController touches no model.
 * A protected item answers core's password form unless the reader's keys open
 * it (I-1700); callers that pass none get the form.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Models\Path;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Addresses\TypeArchives;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Visibility;
use App\Domain\Languages\SiteLanguages;
use App\Domain\People\Credit;
use App\Domain\People\Queries\People;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Site\ViewModels\CommentsPageView;
use App\Domain\Site\ViewModels\LatestView;
use App\Domain\Site\ViewModels\ListingView;
use App\Domain\Site\ViewModels\NotFoundView;
use App\Domain\Site\ViewModels\Pagination;
use App\Domain\Site\ViewModels\ProtectedView;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Themes\ViewIntent;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use Illuminate\Support\Facades\DB;

final class PathResolver
{
    /** The deepest listing page served; deeper pages answer 404 (OFFSET stays bounded), and no listing counts past it. */
    public const int LAST_PAGE = 1000;

    /** A later page of an item's comments, as an address: the item's address before it, and the page's number. */
    public const string COMMENTS_PAGE = '#^(?:(.*)/)?comments/page/(\d+)$#';

    /**
     * The answer for this request path and query string: a redirect, or a
     * page with its intent, page number and view model.
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    public static function resolve(string $rawPath, string $query, ?ReaderKeys $keys = null): array
    {
        $answer = self::answer($rawPath, $query, $keys ?? ReaderKeys::none());

        return $answer['kind'] === 'notfound' ? [...$answer, 'model' => new NotFoundView($rawPath)] : $answer;
    }

    /**
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    private static function answer(string $rawPath, string $query, ReaderKeys $keys): array
    {
        $path = PublicRequestPath::normalise($rawPath);
        $page = 1;

        // A later page of an item's comments (I-272): {item}/comments/page/{n}.
        // An address that names no item is left to the rules below, so a term
        // called "comments" keeps its archive's pages.
        if (preg_match(self::COMMENTS_PAGE, $path, $match) === 1) {
            $comments = self::comments($match[1], (int) $match[2], $rawPath, $query, $keys);

            if ($comments !== null) {
                return $comments;
            }
        }

        // A trailing page/{n}: page 1 is the address itself.
        if (preg_match('#^(?:(.*)/)?page/(\d+)$#', $path, $match) === 1) {
            $path = $match[1];
            $page = (int) $match[2];

            if ($page < 2) {
                return self::redirect(Addresses::toUrl($path), $query, 'current');
            }
        }

        $languages = app(SiteLanguages::class);
        $first = explode('/', $path, 2)[0];
        $tag = $first === '' ? null : $languages->byPrefix($first);

        // No prefix: the default language, in today's order, unchanged.
        if ($tag === null) {
            return self::inDefault($path, $rawPath, $page, $query, $keys);
        }

        $language = $languages->stored($tag);

        // Readers get nothing in a hidden language (I-3360).
        if (! $languages->isShown($language)) {
            return self::notFound(null);
        }

        $rest = $path === $first ? '' : substr($path, strlen($first) + 1);

        if ($rest === '') {
            return self::front($rawPath, $page, $query, $keys, $language);
        }

        // Items and terms in this language store their prefix, so the whole path is looked up first.
        $row = Paths::find($path);

        if ($row !== null && $row->is_current) {
            return self::current($row, $rawPath, $page, $query, $keys, $language);
        }

        $archive = TypeArchives::at($rest);

        if ($archive !== null) {
            return self::typeArchive($archive, $path, $rawPath, $page, $query, $language);
        }

        if ($row !== null) {
            return self::history($row, $page, $query);
        }

        // An author has one row, the default language's; their list in this language is that address after the prefix.
        $author = Paths::find($rest);

        // Of Paths::KINDS, only an author's address, an account's or a person's with no account, is answered this way.
        if ($author !== null && $author->is_current && in_array($author->target_type, ['user', Credit::PERSON], true)) {
            return self::authorIn($author, $path, $rawPath, $page, $query, $language);
        }

        if (preg_match('#^(\d{4})(?:/(\d{2})(?:/(\d{2}))?)?$#', $rest, $date) === 1) {
            return self::dateArchive($path, $date, $rawPath, $page, $query, $language);
        }

        return self::notFound($language);
    }

    /**
     * A path with no language's prefix: the default language's, in the
     * order the site has always answered it.
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    private static function inDefault(string $path, string $rawPath, int $page, string $query, ReaderKeys $keys): array
    {
        if ($path === '') {
            return self::front($rawPath, $page, $query, $keys, null);
        }

        $row = Paths::find($path);

        if ($row !== null && $row->is_current) {
            return self::current($row, $rawPath, $page, $query, $keys);
        }

        // A type's archive page (I-3304): after a live address, which keeps
        // its place, and before an old one, whose address it can take over.
        $archive = TypeArchives::at($path);

        if ($archive !== null) {
            return self::typeArchive($archive, $path, $rawPath, $page, $query, null);
        }

        if ($row !== null) {
            return self::history($row, $page, $query);
        }

        if (preg_match('#^(\d{4})(?:/(\d{2})(?:/(\d{2}))?)?$#', $path, $date) === 1) {
            return self::dateArchive($path, $date, $rawPath, $page, $query, null);
        }

        return self::notFound(null);
    }

    /**
     * A later page of an item's comments; its page 1, which is the item
     * itself; an old address's redirect; or a 404 past the last page, or
     * while the item shows no comments (I-484, I-274). Null when the address
     * before "comments" names no item, so the other rules answer it.
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}|null
     */
    private static function comments(string $itemPath, int $number, string $rawPath, string $query, ReaderKeys $keys): ?array
    {
        $settings = AddressSettings::current();
        $choosesPages = $settings->frontShows === AddressSettings::FRONT_PAGE;

        if ($itemPath === '') {
            if (! $choosesPages || $settings->frontPageId === '') {
                return null;
            }

            $id = $settings->frontPageId;
            $base = '/';
            $language = null;
        } else {
            $row = Paths::find($itemPath);

            if ($row === null || $row->target_type !== 'content') {
                return null;
            }

            // Its type, and the language its page is in, read together (I-3360).
            $item = DB::table('contents')->where('id', $row->target_id)->first(['type', 'language']);
            $type = (string) $item?->type;
            $language = $item?->language === null ? null : (string) $item->language;
            $current = $row->is_current ? $row->path : Paths::current('content', (string) $row->target_id);

            // An item in a hidden language has no comments to show, as a missing one (D7).
            if ($current === null || ($choosesPages && $row->target_id === $settings->postsPageId) || app(SiteLanguages::class)->isHiddenPath($current)) {
                return self::notFound();
            }

            // The page shown as the home page keeps its comments at the site's address.
            $base = $choosesPages && $row->target_id === $settings->frontPageId ? '/' : Addresses::toUrl($current, $type);

            if (! $row->is_current || $base === '/') {
                return self::redirect($number < 2 ? $base : CommentViews::pageUrl($base, $number), $query, 'comments');
            }

            $id = (string) $row->target_id;
        }

        $canonical = CommentViews::pageUrl($base, $number);

        if ($number < 2 || rawurldecode($rawPath) !== $canonical) {
            return self::redirect($number < 2 ? $base : $canonical, $query, 'comments');
        }

        $content = $number > self::LAST_PAGE ? null : self::live($id);

        if ($content !== null && $content->visibility === Visibility::Protected && ! $keys->opens($content)) {
            return self::locked($content, 'comments', $rawPath);
        }

        $view = $content === null ? null : app(CommentViews::class)->page($content, $number, $base);

        if ($content === null || $view === null) {
            return self::notFound();
        }

        $answer = [...self::page('comments', $view, new ViewIntent('comments'), $number), 'language' => $language];

        return $content->visibility === Visibility::Protected ? [...$answer, 'private' => true, 'item' => (string) $content->id] : $answer;
    }

    /**
     * The home page: the latest posts, or the page chosen to be it (I-442).
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    private static function front(string $rawPath, int $page, string $query, ReaderKeys $keys, ?string $language): array
    {
        $settings = AddressSettings::current();
        $home = self::home($language);
        $canonical = Pagination::urlFor($home, $page);

        if ($rawPath !== $canonical) {
            return self::redirect($canonical, $query, 'front');
        }

        // A search, even a blank one, at /?s= and /page/N/?s=, WordPress's own
        // shapes, whatever the home page is (1.6h).
        parse_str($query, $parameters);

        if (array_key_exists('s', $parameters)) {
            return [...self::search(is_string($parameters['s']) ? $parameters['s'] : '', $page, $language), 'language' => $language];
        }

        if ($settings->frontShows === AddressSettings::FRONT_PAGE && $settings->frontPageId !== '') {
            if ($language === null) {
                // One page has no page 2.
                $content = $page === 1 ? self::live($settings->frontPageId) : null;

                return $content === null ? self::notFound(null) : self::article($content, 'front', $keys, $rawPath, isFront: true);
            }

            // The home page's version in this language, while it is live; else this language's latest items.
            $version = self::versionOf($settings->frontPageId, $language);

            if ($version !== null) {
                $content = $page === 1 ? self::live($version) : null;

                if ($content !== null) {
                    return self::article($content, 'front', $keys, $rawPath, isFront: true);
                }

                if ($page > 1) {
                    return self::notFound($language);
                }
            }
        }

        return self::latest($page, null, 'front', isFront: true, language: $language);
    }

    /**
     * A current address: its canonical form first, then the target if live.
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    private static function current(Path $row, string $rawPath, int $page, string $query, ReaderKeys $keys, ?string $prefixLanguage = null): array
    {
        // The kinds of Paths::KINDS with a live row, each drawn below; any other is not found here, never someone's page, and in the prefix's language.
        if (! Paths::known($row->target_type, $row->target_id) || $row->target_type === TypeArchives::TARGET) {
            return self::notFound($prefixLanguage);
        }

        $settings = AddressSettings::current();

        // The page shown as the home page has one address, the site's: its
        // own answers 301 there, even before the recompute has moved it.
        if ($row->target_type === 'content' && $settings->frontShows === AddressSettings::FRONT_PAGE && $settings->frontPageId === $row->target_id) {
            return self::redirect('/', $query, 'front');
        }

        // Its type, and the language it is in, read together (I-3360).
        $item = $row->target_type === 'content' ? DB::table('contents')->where('id', $row->target_id)->first(['type', 'language', 'translation_group']) : null;
        $type = $item === null ? null : (string) $item->type;

        // A type or taxonomy no longer registered, as a disabled plugin's, has no page (docs/architecture/plugins.md).
        if ($type !== null && ! app(ContentTypeRegistry::class)->has($type)) {
            return self::notFound($prefixLanguage);
        }

        if ($row->target_type === 'term' && ! app(TaxonomyRegistry::class)->has((string) DB::table('terms')->where('id', $row->target_id)->value('taxonomy'))) {
            return self::notFound($prefixLanguage);
        }

        $canonical = Pagination::urlFor(Addresses::toUrl($row->path, $type), $page);

        if (rawurldecode($rawPath) !== $canonical || preg_match('/%(?:3[0-9]|4[1-9a-f]|5[0-9a]|6[1-9a-f]|7[0-9a]|2d)/i', $rawPath) === 1) {
            return self::redirect($canonical, $query, 'current');
        }

        if ($row->target_type === 'content') {
            $language = $item?->language === null ? null : (string) $item->language;

            // The posts page lists the latest posts rather than showing itself; its version in another language, that language's.
            if ($settings->frontShows === AddressSettings::FRONT_PAGE && $settings->postsPageId !== '' && self::isVersionOf($row->target_id, $item?->translation_group, $settings->postsPageId)) {
                $postsPage = self::live($row->target_id);

                return $postsPage === null ? self::notFound($language) : self::latest($page, $postsPage, 'current', isFront: false, language: $language);
            }

            $content = $page === 1 ? self::live($row->target_id) : null;

            return $content === null ? self::notFound($language) : self::article($content, 'current', $keys, $rawPath);
        }

        if ($page > self::LAST_PAGE) {
            return self::notFound($prefixLanguage);
        }

        if ($row->target_type === 'term') {
            $term = Term::query()->find($row->target_id);
            $language = $term?->language === null ? null : (string) $term->language;

            return $term === null ? self::notFound($prefixLanguage) : [...self::listing(app(ListingViews::class)->forTerm($term, $page), $page, 'current', new ViewIntent('taxonomy', taxonomy: (string) $term->taxonomy, term: (string) $term->slug)), 'language' => $language];
        }

        $shown = self::author($row);

        return $shown === null ? self::notFound($prefixLanguage) : self::listing(app(ListingViews::class)->forAuthor($shown, $page), $page, 'current', new ViewIntent('author'));
    }

    /**
     * An author's items in another language, at their address after its
     * prefix: their canonical form first, then the listing.
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    private static function authorIn(Path $row, string $path, string $rawPath, int $page, string $query, string $language): array
    {
        $canonical = Pagination::urlFor(Addresses::toUrl($path), $page);

        if (rawurldecode($rawPath) !== $canonical) {
            return self::redirect($canonical, $query, 'current');
        }

        $shown = $page > self::LAST_PAGE ? null : self::author($row);

        return $shown === null
            ? self::notFound($language)
            : [...self::listing(app(ListingViews::class)->forAuthor($shown, $page, $language), $page, 'current', new ViewIntent('author')), 'language' => $language];
    }

    /**
     * The author an author page's row names, as People::shown() gives them:
     * an account by the ID its row holds (Paths::account()), never another's
     * by an integer cast, or a person with no account by theirs; null for
     * no one, or a row of any other kind.
     *
     * @return object{kind: string, id: string, name: string, slug: string, bio: string|null, path: string|null}|null
     */
    private static function author(Path $row): ?object
    {
        $account = Paths::account($row->target_type, $row->target_id);
        $credit = match (true) {
            $account !== null => Credit::account($account),
            $row->target_type === Credit::PERSON => Credit::person((string) $row->target_id),
            default => null,
        };

        return $credit === null ? null : (app(People::class)->shown([$credit])[$credit->key()] ?? null);
    }

    /**
     * An old address: a permanent redirect to the target's current one, the
     * page and query kept, or a 404 when it has none.
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    private static function history(Path $row, int $page, string $query): array
    {
        // An archive page's old address leads to where its setting puts it now; a kind not in Paths::KINDS leads nowhere.
        $current = match (true) {
            $row->target_type === TypeArchives::TARGET => TypeArchives::path((string) $row->target_id),
            Paths::known($row->target_type, $row->target_id) => Paths::current($row->target_type, $row->target_id),
            default => null,
        };

        // Its target in a hidden language is as missing as one that's gone (D7).
        if ($current === null || app(SiteLanguages::class)->isHiddenPath($current)) {
            return self::notFound();
        }

        $type = $row->target_type === 'content' ? (string) DB::table('contents')->where('id', $row->target_id)->value('type') : null;

        return self::redirect(Pagination::urlFor(Addresses::toUrl($current, $type), $page), $query, 'history');
    }

    /**
     * A type's archive page (I-3304): its canonical form first, then the
     * page; a page past the last is a 404.
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    private static function typeArchive(string $type, string $path, string $rawPath, int $page, string $query, ?string $language): array
    {
        $canonical = Pagination::urlFor(Addresses::toUrl($path), $page);

        // As current() does: a letter, digit or hyphen sent encoded is never served as the address (app/Domain/Site/PathResolver.php).
        if (rawurldecode($rawPath) !== $canonical || preg_match('/%(?:3[0-9]|4[1-9a-f]|5[0-9a]|6[1-9a-f]|7[0-9a]|2d)/i', $rawPath) === 1) {
            return self::redirect($canonical, $query, 'type-archive');
        }

        if ($page > self::LAST_PAGE) {
            return self::notFound($language);
        }

        return [...self::listing(app(ListingViews::class)->forType($type, $path, $page, $language), $page, 'type-archive', new ViewIntent('archive', type: $type)), 'language' => $language];
    }

    /**
     * A year, month or day: the posts published in it, on the site's clock.
     *
     * @param  array<int, string>  $date
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    private static function dateArchive(string $path, array $date, string $rawPath, int $page, string $query, ?string $language): array
    {
        $canonical = Pagination::urlFor(Addresses::toUrl($path), $page);

        if (rawurldecode($rawPath) !== $canonical) {
            return self::redirect($canonical, $query, 'date');
        }

        $year = (int) $date[1];
        $month = isset($date[2]) ? (int) $date[2] : null;
        $day = isset($date[3]) ? (int) $date[3] : null;

        if ($page > self::LAST_PAGE || ($month !== null && ($month < 1 || $month > 12)) || ($day !== null && $month !== null && ! checkdate($month, $day, $year))) {
            return self::notFound($language);
        }

        return [...self::listing(app(ListingViews::class)->forDate($year, $month, $day, $page, $language), $page, 'date', new ViewIntent('date')), 'language' => $language];
    }

    /**
     * The latest posts, at the site's address or the posts page's; a page past the last is a 404.
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    private static function latest(int $page, ?Content $postsPage, string $address, bool $isFront, ?string $language = null): array
    {
        if ($page > self::LAST_PAGE) {
            return self::notFound($language);
        }

        $view = app(LatestViews::class)->latest($page, $postsPage, $language);

        return $page > 1 && $view->feed->items === []
            ? self::notFound($language)
            : [...self::page($address, $view, new ViewIntent('latest', isFront: $isFront), $page), 'language' => $language];
    }

    /**
     * A search's page in one language, which is never the home page; a page past the last is a 404.
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    private static function search(string $query, int $page, ?string $language): array
    {
        if ($page > self::LAST_PAGE) {
            return self::notFound($language);
        }

        return self::listing(app(SearchViews::class)->for($query, $page, $language), $page, 'search', new ViewIntent('search'));
    }

    /**
     * A listing's page; a page past the last is a 404.
     *
     * @return array{kind: string, address: string, redirect?: string, intent?: ViewIntent, pageNumber?: int, model?: ArticleView|ListingView|LatestView|NotFoundView|CommentsPageView|ProtectedView, private?: bool, item?: string, language?: string|null}
     */
    private static function listing(ListingView $view, int $page, string $address, ViewIntent $intent): array
    {
        return $page > 1 && $view->items === [] ? self::notFound() : self::page($address, $view, $intent, $page);
    }

    /**
     * An article, drawn by its type's template and the one it chose; core's
     * password form in its place for a protected item the reader holds no
     * key to (I-1700).
     *
     * @return array{kind: string, address: string, model: ArticleView|ProtectedView, intent: ViewIntent, pageNumber: int, private?: true, item?: string}
     */
    private static function article(Content $content, string $address, ReaderKeys $keys, string $rawPath, bool $isFront = false): array
    {
        if ($content->visibility === Visibility::Protected && ! $keys->opens($content)) {
            return self::locked($content, $address, $rawPath);
        }

        $view = app(ArticleViews::class)->published($content);
        $answer = [...self::page($address, $view, new ViewIntent('single', type: $view->type, template: $view->template, isFront: $isFront), 1), 'language' => $content->language];

        // Open to this reader alone: never stored, never shared (I-1700).
        return $content->visibility === Visibility::Protected ? [...$answer, 'private' => true, 'item' => (string) $content->id] : $answer;
    }

    /**
     * Core's password form in place of a protected item, at its address or
     * a later page of its comments, for a reader without its key (I-1700).
     *
     * @return array{kind: string, address: string, model: ProtectedView, intent: ViewIntent, pageNumber: int, private: true, item: string}
     */
    private static function locked(Content $content, string $address, string $rawPath): array
    {
        // What it shows, so a change to the item reaches anything that drew it; the page itself is never stored.
        app(RenderRecord::class)->tag(PageTags::content((string) $content->id));
        $types = app(ContentTypeRegistry::class);
        $view = new ProtectedView(
            app(ArticleViews::class)->headline($content)['title'],
            $types->has($content->type) ? mb_strtolower($types->get($content->type)->singular) : __('page'),
            $rawPath,
        );

        return [...self::page($address, $view, new ViewIntent('protected'), 1), 'private' => true, 'item' => (string) $content->id, 'language' => $content->language];
    }

    /**
     * The item when it is published, live, dated now or earlier and not private, or null.
     */
    private static function live(string $id): ?Content
    {
        // An item in a hidden language is as missing as one never published (D7).
        return app(SiteLanguages::class)->whereShown(Content::query())
            ->whereKey($id)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereNotNull('published_revision_id')
            ->where('published_at', '<=', now())
            // Private items are read in the admin, never here (I-1700).
            ->where('visibility', '<>', 'private')
            ->first();
    }

    /**
     * @return array{kind: string, address: string, model: ArticleView|ListingView|LatestView|CommentsPageView|ProtectedView, intent: ViewIntent, pageNumber: int}
     */
    private static function page(string $address, ArticleView|ListingView|LatestView|CommentsPageView|ProtectedView $model, ViewIntent $intent, int $page): array
    {
        return ['kind' => 'view', 'address' => $address, 'model' => $model, 'intent' => $intent, 'pageNumber' => $page];
    }

    /**
     * @return array{kind: string, address: string, redirect: string}
     */
    private static function redirect(string $url, string $query, string $address): array
    {
        return ['kind' => 'redirect', 'address' => $address, 'redirect' => PublicRequestPath::encode($url).($query === '' ? '' : '?'.$query)];
    }

    /**
     * @return array{kind: string, address: string, intent: ViewIntent, pageNumber: int}
     */
    private static function notFound(?string $language = null): array
    {
        return ['kind' => 'notfound', 'address' => 'notfound', 'intent' => new ViewIntent('notfound'), 'pageNumber' => 1, 'language' => $language];
    }

    /**
     * A language's home: the site's address for the default, its prefix for another.
     */
    private static function home(?string $language): string
    {
        return $language === null ? '/' : '/'.app(SiteLanguages::class)->prefix($language).'/';
    }

    /**
     * The ID of this item's version in another language, in its translation
     * group, live or not, or null.
     */
    private static function versionOf(string $id, string $language): ?string
    {
        $version = DB::table('contents as version')
            ->join('contents as item', 'item.translation_group', '=', 'version.translation_group')
            ->where('item.id', $id)
            ->where('version.language', $language)
            ->whereNull('version.deleted_at')
            ->value('version.id');

        return $version === null ? null : (string) $version;
    }

    /**
     * Whether the item is this other item, or a version of it in its translation group.
     */
    private static function isVersionOf(string $id, mixed $group, string $otherId): bool
    {
        if ($id === $otherId) {
            return true;
        }

        return $group !== null && DB::table('contents')->where('id', $otherId)->where('translation_group', $group)->exists();
    }
}
