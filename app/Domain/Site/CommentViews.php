<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Paths;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Cache\PageCache;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\LinkResolver;
use App\Domain\Comments\CommentBody;
use App\Domain\Comments\CommenterToken;
use App\Domain\Comments\CommentKind;
use App\Domain\Comments\CommentPicture;
use App\Domain\Comments\CommentStatus;
use App\Domain\Comments\Discussion;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Queries\CommentThreads;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Visibility;
use App\Domain\Languages\SiteLanguages;
use App\Domain\People\Credit;
use App\Domain\People\Credits;
use App\Domain\Settings\Modules;
use App\Domain\Site\ViewModels\CommentFormView;
use App\Domain\Site\ViewModels\CommentsPageView;
use App\Domain\Site\ViewModels\CommentsView;
use App\Domain\Site\ViewModels\CommentTarget;
use App\Domain\Site\ViewModels\CommentView;
use App\Domain\Site\ViewModels\OwnComment;
use App\Domain\Site\ViewModels\Pagination;
use App\Domain\Types\ContentTypeRegistry;
use Carbon\CarbonImmutable;

/**
 * An item's comments as the public site gives them to a theme
 * (docs/studies/comments.md §3.1, I-272): its thread on one page with the
 * pages' addresses, a later page, a comment's own address, and the item a
 * comment form posts to, as view models that hold a name and a website
 * and nothing more personal (D19), and a browser's own comments (1.8b′).
 * Nothing at all while comments are switched off, the item is Hidden or its
 * type takes none (I-484, I-274).
 */
final readonly class CommentViews
{
    public function __construct(
        private CommentThreads $threads,
        private ContentTypeRegistry $types,
        private SiteLanguage $language,
    ) {}

    /**
     * The item's thread on page $number, as a theme is given it: null while
     * comments are switched off, the item is Hidden or its type takes none,
     * and for a page past the last. $address is the item's own address when
     * the caller has read it already, so a page reads it once; $credited the
     * accounts the item credits, likewise (I-3366).
     *
     * @param  list<int>|null  $credited
     */
    public function forItem(Content $item, int $number = 1, ?string $address = null, ?array $credited = null): ?CommentsView
    {
        if (! $this->shows($item)) {
            return null;
        }

        app(RenderRecord::class)->tag(PageTags::comments((string) $item->id));
        $settings = DiscussionSettings::current();
        $stats = $this->threads->stats((string) $item->id);
        $total = max(1, (int) ceil($stats['threads'] / $settings->perPage));

        if ($number < 1 || $number > $total) {
            return null;
        }

        // The page shown as the home page keeps its thread at the site's address.
        $itemUrl = $this->isFrontPage($item) || $address === null || $address === '' ? $this->itemUrl($item) : $address;
        $rows = $stats['threads'] === 0 ? [] : $this->threads->page((string) $item->id, $number, $settings->perPage);
        $open = $this->open($item);

        return new CommentsView(
            count: $stats['approved'],
            open: $open,
            current: $number,
            total: $total,
            depth: $settings->threadDepth,
            items: $this->tree($rows, $item, $open, $credited),
            pagination: new Pagination(
                $number,
                $total,
                $number > 1 ? self::pageUrl($itemUrl, $number - 1) : null,
                $number < $total ? self::pageUrl($itemUrl, $number + 1) : null,
                self::base($itemUrl),
                '',
                self::pageUrl($itemUrl, 1),
            ),
            form: $open ? new CommentFormView(route('teme.comments.store', [], false), $itemUrl, null, null, $settings->askWebsite, $settings->signedInOnly, $settings->keepIpDays) : null,
        );
    }

    /**
     * A later page of the item's comments, as its template is given it: the
     * item's title, address and kicker, and the thread's page; null when the
     * item shows no comments or the page is past the last. Page 1 is the
     * item itself, whose article draws it.
     */
    public function page(Content $item, int $number, string $address): ?CommentsPageView
    {
        $comments = $this->forItem($item, $number, $address);

        if ($comments === null) {
            return null;
        }

        // The page shows the item's title and links to it.
        app(RenderRecord::class)->tag(PageTags::content((string) $item->id));
        $headline = app(ArticleViews::class)->headline($item);

        return new CommentsPageView($headline['title'], $address, $headline['kicker'], $comments);
    }

    /**
     * The address of the page a shown comment is on, at `#comment-{id}`, its
     * path encoded as a redirect's is; null while comments are switched off,
     * for a comment not shown with everything above it (D3), and for one on
     * an item that isn't live, whose type takes none or that is Hidden.
     */
    public function permalink(string $commentId): ?string
    {
        if (! Modules::on(Modules::COMMENTS)) {
            return null;
        }

        $comment = $this->threads->shown($commentId);
        $item = $comment === null ? null : self::live($comment->content_id);

        if ($comment === null || $item === null || ! $this->shows($item)) {
            return null;
        }

        $itemUrl = $this->itemUrl($item);
        $number = $this->threads->pageOf($comment, DiscussionSettings::current()->perPage);

        return PublicRequestPath::encode($number === 1 ? $itemUrl : self::pageUrl($itemUrl, $number)).'#comment-'.$comment->id;
    }

    /**
     * The live item a comment form's address names (D9): a current or an old
     * address of a content item, or the site's own for the page chosen as the
     * home page, never the posts page; null for anything else.
     */
    public function target(string $path): ?CommentTarget
    {
        $item = $this->item($path);

        return $item === null ? null : new CommentTarget((string) $item->id, app(ArticleViews::class)->headline($item)['title'], $this->itemUrl($item));
    }

    /**
     * The live item an address names: a current or an old address of a
     * content item, or the site's own for the page chosen as the home page,
     * never the posts page; null for anything else.
     */
    private function item(string $path): ?Content
    {
        $path = PublicRequestPath::normalise($path);
        $settings = AddressSettings::current();
        $choosesPages = $settings->frontShows === AddressSettings::FRONT_PAGE;

        if ($path === '') {
            $id = $choosesPages && $settings->frontPageId !== '' ? $settings->frontPageId : null;
        } else {
            $row = Paths::find($path);
            $id = $row === null || $row->target_type !== 'content' || ($choosesPages && $row->target_id === $settings->postsPageId) ? null : (string) $row->target_id;
        }

        return $id === null ? null : self::live($id);
    }

    /**
     * This browser's own comments on the item a form's address names (I-276,
     * 1.8b′'s D6): those that wait, spam read as waiting (I-592), and those
     * approved since a stored page could have been drawn, which the page may
     * lack; each drawn as its writer sees it, a reply inside the place of the
     * comment it's stored under (D7). Nothing for a key that isn't one, read
     * before anything else, and nothing while the item shows no comments.
     *
     * @return list<OwnComment>
     */
    public function own(string $path, string $key): array
    {
        if (! CommenterToken::valid($key) || ! Modules::on(Modules::COMMENTS)) {
            return [];
        }

        $item = $this->item($path);

        if ($item === null || ! $this->shows($item)) {
            return [];
        }

        // The longest a stored page, or a copy set aside, can lag behind what it shows.
        $since = now()->subSeconds(PageCache::lifetime() + PageCache::RENDER_WINDOW);

        return $this->places($this->threads->own((string) $item->id, CommenterToken::hash($key), $since), $item);
    }

    /**
     * Each row drawn as its writer sees it: waiting unless approved, a reply
     * inside the place of the shown comment it's stored under; a reply whose
     * comment isn't shown has nowhere to go, and is left out (D3).
     *
     * @param  list<Comment>  $rows
     *
     * @return list<OwnComment>
     */
    private function places(array $rows, Content $item): array
    {
        $parents = $this->threads->parentsOf($rows);
        $open = $this->open($item);
        $links = app(LinkResolver::class);
        $credited = array_any([...$rows, ...array_values($parents)], fn (Comment $row): bool => $row->author_user_id !== null) ? self::credited($item) : [];
        $places = [];

        foreach ($rows as $row) {
            $parent = $row->parent_id === null ? null : ($parents[$row->parent_id] ?? null);

            if ($row->parent_id !== null && $parent === null) {
                continue;
            }

            $holder = $parent === null ? null : $this->view($parent, $item, $open, null, $links, credited: $credited);
            $own = $this->view($row, $item, $open, $holder, $links, $row->status !== CommentStatus::APPROVED, $credited);
            $places[] = new OwnComment($row->id, $row->parent_id, $holder === null ? $own : self::holding($holder, [$own]));
        }

        return $places;
    }

    /**
     * The comment just posted, as its writer sees it, for core's script to put
     * in place (1.8b′'s D8); null when it isn't on the target's live item.
     */
    public function justPosted(CommentTarget $target, string $commentId): ?OwnComment
    {
        $row = $this->threads->written($commentId);
        $item = $row === null || $row->content_id !== $target->id ? null : self::live($target->id);

        return $row === null || $item === null ? null : ($this->places([$row], $item)[0] ?? null);
    }

    /**
     * The address of page $number of an item's comments: page 1 is the item
     * itself, at #comments; a later one is `{item}/comments/page/{n}`,
     * following the item's own trailing slash.
     */
    public static function pageUrl(string $itemUrl, int $number): string
    {
        return $number < 2 ? $itemUrl.'#comments' : Pagination::urlFor(self::base($itemUrl), $number);
    }

    /**
     * The item's address as its thread links to it: the site's own for the
     * page chosen as the home page, otherwise its current one.
     */
    public function itemUrl(Content $item): string
    {
        return $this->isFrontPage($item) ? '/' : (Addresses::relativeUrl($item) ?? '/');
    }

    /**
     * One place in the thread as a theme is given it: a comment, a tombstone
     * with nothing personal left in it (I-278) or a ping kept from WordPress
     * (I-277), with no replies; Reply only while the item takes comments and
     * the comment is an approved one of a reader's; drawn as waiting, for its
     * writer alone, when $pending (1.8b′). $credited is the accounts the
     * item credits, read here when not given.
     *
     * @param  list<int>|null  $credited
     */
    public function view(Comment $row, Content $item, bool $open, ?CommentView $parent, LinkResolver $links, bool $pending = false, ?array $credited = null): CommentView
    {
        $credited ??= $row->author_user_id === null ? [] : self::credited($item);
        $removed = $row->status === CommentStatus::REMOVED;
        $written = $row->kind === CommentKind::Comment;
        $name = $removed ? null : $row->author_name;
        $moment = SiteDates::inSite($row->created_at ?? now());

        return new CommentView(
            id: $row->id,
            kind: $row->kind->value,
            removed: $removed,
            name: $name,
            url: $removed ? null : $row->author_url,
            // Written by someone the item credits, known from their account (I-597).
            byAuthor: ! $removed && $row->author_user_id !== null && in_array((int) $row->author_user_id, $credited, true),
            initials: $removed ? '' : CommentPicture::initials((string) $name),
            hue: $removed ? 0 : CommentPicture::hue((string) $name),
            iso: $moment->toIso8601String(),
            dateText: SiteDates::show($moment, $this->language->forContent($item), 'LLL'),
            permalink: route('teme.comments.show', ['comment' => $row->id], false),
            replyUrl: $open && ! $removed && $written && $row->status === CommentStatus::APPROVED
                ? route('teme.comments.reply', ['comment' => $row->id], false)
                : null,
            legacyId: $row->legacy_id,
            bodyHtml: $removed || ! $written ? '' : CommentBody::html($row->body ?? [], $links),
            isReply: $row->depth > 1,
            parentName: $parent?->name,
            pending: $pending,
            replies: [],
        );
    }

    /**
     * Whether the item shows its comments at all: its type takes them, it
     * isn't Hidden, and comments are switched on. The first two read nothing.
     */
    private function shows(Content $item): bool
    {
        return $this->types->has($item->type)
            && $this->types->get($item->type)->comments !== null
            && $item->comment_status !== Discussion::Hidden
            && Modules::on(Modules::COMMENTS);
    }

    /**
     * Whether the item takes new comments now: Open, and within the days the
     * settings allow after its date, as SubmitComment asks (I-274). Its
     * thread's form and Reply links, and the reply page, ask the same.
     */
    public function open(Content $item): bool
    {
        // Only public items take comments (I-1700).
        if ($item->visibility !== Visibility::Public || $item->comment_status !== Discussion::Open || $item->published_at === null) {
            return false;
        }

        $days = DiscussionSettings::current()->closeAfterDays;

        // Parsed, as the house does: Larastan reads published_at as a string (B-350).
        return $days === 0 || ! CarbonImmutable::parse((string) $item->published_at)->lt(now()->subDays($days));
    }

    /**
     * The item when it is published, live and dated now or earlier, or
     * null: the only items whose comments have addresses of their own.
     */
    public static function live(string $id): ?Content
    {
        // An item in a hidden language is as missing as one never published (D7).
        return app(SiteLanguages::class)->whereShown(Content::query())
            ->whereKey($id)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->whereNotNull('published_revision_id')
            ->where('published_at', '<=', now())
            // Only public items go beyond the site's own pages (I-1700).
            ->where('visibility', 'public')
            ->first();
    }

    private function isFrontPage(Content $item): bool
    {
        $settings = AddressSettings::current();

        return $settings->frontShows === AddressSettings::FRONT_PAGE && $settings->frontPageId === (string) $item->id;
    }

    /**
     * The page's rows as a tree of views: each conversation's first comment
     * with every reply under it, in path order, and each comment drawn
     * recorded (D5). One link resolver serves the whole thread.
     *
     * @param  list<Comment>  $rows
     * @param  list<int>|null  $credited  the accounts the item credits, read here when not given
     *
     * @return list<CommentView>
     */
    private function tree(array $rows, Content $item, bool $open, ?array $credited = null): array
    {
        $children = [];
        $firsts = [];

        foreach ($rows as $row) {
            if ($row->depth === 1) {
                $firsts[] = $row;
            } else {
                $children[(string) $row->parent_id][] = $row;
            }
        }

        $links = app(LinkResolver::class);
        $credited ??= array_any($rows, fn (Comment $row): bool => $row->author_user_id !== null) ? self::credited($item) : [];

        return array_map(fn (Comment $row): CommentView => $this->branch($row, $children, $item, $open, null, $links, $credited), $firsts);
    }

    /**
     * One comment's view with its replies' views under it, made depth-first:
     * a reply's view names the comment it's under.
     *
     * @param  array<string, list<Comment>>  $children
     * @param  list<int>  $credited  the accounts the item credits
     */
    private function branch(Comment $row, array $children, Content $item, bool $open, ?CommentView $parent, LinkResolver $links, array $credited): CommentView
    {
        app(RenderRecord::class)->tag(PageTags::comment($row->id));
        $view = $this->view($row, $item, $open, $parent, $links, credited: $credited);
        $replies = array_map(fn (Comment $child): CommentView => $this->branch($child, $children, $item, $open, $view, $links, $credited), $children[$row->id] ?? []);

        return $replies === [] ? $view : self::holding($view, $replies);
    }

    /**
     * The accounts the item credits, whose comments are marked as the
     * author's: its bylines' accounts, or its owner with none (I-3366).
     *
     * @return list<int>
     */
    private static function credited(Content $item): array
    {
        $credits = app(Credits::class)->forItems([(string) $item->id => $item->author_id === null ? null : (int) $item->author_id])[(string) $item->id] ?? [];

        return array_values(array_map(fn (Credit $credit): int => (int) $credit->id, array_filter($credits, fn (Credit $credit): bool => $credit->kind === Credit::ACCOUNT)));
    }

    /**
     * The view with these replies under it: a view is readonly, so it is made again.
     *
     * @param  list<CommentView>  $replies
     */
    private static function holding(CommentView $view, array $replies): CommentView
    {
        return new CommentView(
            $view->id, $view->kind, $view->removed, $view->name, $view->url, $view->byAuthor, $view->initials, $view->hue,
            $view->iso, $view->dateText, $view->permalink, $view->replyUrl, $view->legacyId, $view->bodyHtml,
            $view->isReply, $view->parentName, $view->pending, $replies,
        );
    }

    /**
     * The address later pages hang from: the item's, then `comments`, with
     * the item's own trailing slash.
     */
    private static function base(string $itemUrl): string
    {
        return rtrim($itemUrl, '/').'/comments'.(str_ends_with($itemUrl, '/') ? '/' : '');
    }
}
