<?php

/**
 * Resolves a public page's head values once, from its kind, its view model,
 * the item's stored fields and Search and social's settings, and keeps them
 * while that page is drawn, so the title and the head's entries agree.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Head;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\LinkResolver;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Rules\CanonicalChoice;
use App\Domain\Plugins\Images\ImageServices;
use App\Domain\Plugins\Seo\PluginPageTitle;
use App\Domain\Seo\SocialCards;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Site\ViewModels\CommentsPageView;
use App\Domain\Site\ViewModels\ErrorView;
use App\Domain\Site\ViewModels\LatestView;
use App\Domain\Site\ViewModels\ListingView;
use App\Domain\Site\ViewModels\NotFoundView;
use App\Domain\Site\ViewModels\Pagination;
use App\Domain\Site\ViewModels\ProtectedView;
use App\Domain\Site\ViewModels\RespondView;
use App\Domain\Types\ContentTypeRegistry;
use Closure;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

final class PageHead
{
    /** The view model whose values are kept, compared by identity. */
    private ?object $model = null;

    private ?HeadValues $values = null;

    public function __construct(private readonly SettingsReader $settings) {}

    /** The page's head values: resolved once for its view model, kept while that page is drawn. */
    public function for(DocumentPage $page): HeadValues
    {
        if ($page->model !== null && $page->model === $this->model && $this->values !== null) {
            return $this->values;
        }

        // A plugin's title, when one gives it, goes in here, so everything that reads the head agrees.
        $this->values = app(PluginPageTitle::class)->apply($page, $this->resolve($page));
        $this->model = $page->model;

        return $this->values;
    }

    private function resolve(DocumentPage $page): HeadValues
    {
        $model = $page->model;
        $kind = $page->intent?->kind;
        $isFront = $page->intent->isFront ?? false;
        $site = $this->settings->string('general', 'site_title');
        $tagline = trim($this->settings->string('general', 'tagline'));
        $tokens = ['title' => $site, 'site' => $site, 'tagline' => $tagline];

        return match (true) {
            $model instanceof ArticleView => $this->article($model, $kind, $isFront, $tokens),
            $model instanceof LatestView => $isFront
                ? $this->listing($model->feed, 'home', $kind, false, $tokens, $tagline === '' ? null : $tagline)
                : $this->postsPage($model, $kind, $tokens),
            $model instanceof ListingView && $kind === 'search' => $this->search($model, $tokens),
            $model instanceof ListingView => $this->archive($model, $page, $kind, $tokens),
            $model instanceof CommentsPageView => $this->plain(__('Comments on :title, page :number', ['title' => $model->title, 'number' => $model->comments->current]), $kind, $tokens, $this->here()),
            $model instanceof RespondView => $this->plain($model->title, $kind, $tokens),
            $model instanceof ProtectedView => $this->plain($model->title, $kind, $tokens),
            $model instanceof NotFoundView, $model instanceof ErrorView && $model->code === 404 => $this->plain(__('Page not found'), $kind, $tokens, null, 'not_found'),
            $model instanceof ErrorView => $this->plain(__('Something went wrong'), $kind, $tokens),
            default => new HeadValues($site, null, null, null, RobotsMeta::directives($kind, false), null, null, 'website', null, null, null),
        };
    }

    /**
     * An item, on its own address or as the site's (I-290, I-292, I-293).
     *
     * @param  array<string, string|Closure(): string>  $tokens
     */
    private function article(ArticleView $model, ?string $kind, bool $isFront, array $tokens): HeadValues
    {
        // The revision the page shows, a preview's included (I-900).
        $fields = $model->fields;
        $seoTitle = self::text($fields[BuiltInFields::SEO_TITLE] ?? null);
        $excerpt = self::text($fields[BuiltInFields::EXCERPT] ?? null);
        $description = self::text($fields[BuiltInFields::META_DESCRIPTION] ?? null) ?? $excerpt;
        $hidden = ($fields[BuiltInFields::NOINDEX] ?? false) === true;
        $types = app(ContentTypeRegistry::class);

        if ($isFront) {
            $description ??= $tokens['tagline'] === '' ? null : $tokens['tagline'];
            $url = $this->root().'/';
            $title = $seoTitle ?? $this->fill('home', $tokens);
        } else {
            $hidden = $hidden || TitlesAndListing::hidesType($model->type);
            $url = $model->url === '' ? null : $this->root().PublicRequestPath::encode($model->url);
            $tokens['title'] = $model->title;
            $tokens['term'] = $model->kicker->name ?? '';
            $tokens['author'] = $model->authors[0]->name ?? '';
            $tokens['type'] = $types->has($model->type) ? $types->get($model->type)->singular : '';
            $tokens['excerpt'] = $excerpt ?? '';
            $tokens['date'] = $model->publishedText;
            $tokens['year'] = $model->published->format('Y');
            $title = $seoTitle ?? $this->fill("type:{$model->type}", $tokens);
        }

        $robots = RobotsMeta::directives($kind, $hidden, $fields);
        $canonical = ! in_array('noindex', $robots, true) && $url !== null ? $this->canonical($model->id, $url, $fields) : null;

        return new HeadValues(
            $title,
            $description,
            $url,
            $canonical,
            $robots,
            // The home page shares under the title its tab shows, not the page's own name, such as "Home".
            self::text($fields[BuiltInFields::SOCIAL_TITLE] ?? null) ?? ($isFront ? $title : $seoTitle ?? $model->title),
            self::text($fields[BuiltInFields::SOCIAL_DESCRIPTION] ?? null) ?? $description,
            $isFront ? 'website' : 'article',
            $this->image($fields, $model->firstImageId),
            null,
            null,
        );
    }

    /**
     * The posts page, resolved as its page item: its SEO title, description,
     * box and type's row.
     *
     * @param  array<string, string|Closure(): string>  $tokens
     */
    private function postsPage(LatestView $model, ?string $kind, array $tokens): HeadValues
    {
        $stored = $this->stored(AddressSettings::current()->postsPageId);
        $fields = $stored['fields'];
        $seoTitle = self::text($fields[BuiltInFields::SEO_TITLE] ?? null);
        $description = self::text($fields[BuiltInFields::META_DESCRIPTION] ?? null) ?? self::text($fields[BuiltInFields::EXCERPT] ?? null);
        $hidden = ($fields[BuiltInFields::NOINDEX] ?? false) === true || TitlesAndListing::hidesType($stored['type']);
        $tokens['title'] = $model->feed->subject->title ?? '';
        $tokens['page'] = $this->pageText($model->feed->pagination);
        $title = $seoTitle ?? $this->fill("type:{$stored['type']}", $tokens);
        $robots = RobotsMeta::directives($kind, $hidden, $fields);
        $url = $this->here();

        return new HeadValues(
            $title,
            $description,
            $url,
            in_array('noindex', $robots, true) ? null : $url,
            $robots,
            self::text($fields[BuiltInFields::SOCIAL_TITLE] ?? null) ?? $seoTitle ?? $tokens['title'],
            self::text($fields[BuiltInFields::SOCIAL_DESCRIPTION] ?? null) ?? $description,
            'website',
            $this->image($fields, $stored['firstImage']),
            $this->absolute($model->feed->pagination->previous),
            $this->absolute($model->feed->pagination->next),
        );
    }

    /**
     * A term's, an author's, a date's or a type's archive, each from its own row.
     *
     * @param  array<string, string|Closure(): string>  $tokens
     */
    private function archive(ListingView $model, DocumentPage $page, ?string $kind, array $tokens): HeadValues
    {
        $heading = $model->subject->title ?? '';
        $type = (string) $page->intent?->type;
        $taxonomy = (string) $page->intent?->taxonomy;

        [$row, $token, $hidden, $description] = match ($kind) {
            'taxonomy' => ["taxonomy:{$taxonomy}", 'term', TitlesAndListing::hidesTaxonomy($taxonomy), $model->subject?->description],
            'author' => ['author', 'author', TitlesAndListing::hidesAuthors(), $model->subject?->description],
            'archive' => ["archive:{$type}", 'type', TitlesAndListing::hidesArchive($type), null],
            default => ['date', 'date', TitlesAndListing::hidesDates(), null],
        };

        $tokens['title'] = $heading;
        $tokens[$token] = $heading;

        return $this->listing($model, $row, $kind, $hidden, $tokens, self::text($description));
    }

    /**
     * A listing drawn from a row: an archive, or the latest posts at the site's address.
     *
     * @param  array<string, string|Closure(): string>  $tokens
     */
    private function listing(ListingView $listing, string $row, ?string $kind, bool $hidden, array $tokens, ?string $description): HeadValues
    {
        $tokens['page'] = $this->pageText($listing->pagination);
        $robots = RobotsMeta::directives($kind, $hidden);
        $url = $this->here();
        $heading = $tokens['title'];

        return new HeadValues(
            $this->fill($row, $tokens),
            $description,
            $url,
            in_array('noindex', $robots, true) ? null : $url,
            $robots,
            is_string($heading) ? $heading : '',
            $description,
            'website',
            $this->image([], null),
            $this->absolute($listing->pagination->previous),
            $this->absolute($listing->pagination->next),
        );
    }

    /**
     * A search's page, which search engines are always asked not to list (I-851).
     *
     * @param  array<string, string|Closure(): string>  $tokens
     */
    private function search(ListingView $model, array $tokens): HeadValues
    {
        $query = $model->search->query ?? '';
        $tokens['title'] = $model->subject->title ?? '';
        $tokens['search'] = $query;
        $tokens['page'] = $this->pageText($model->pagination, $model->search->totalCapped ?? false);

        return new HeadValues(
            $this->fill('search', $tokens),
            null,
            $this->here().'?s='.rawurlencode($query),
            null,
            RobotsMeta::directives('search', true),
            null,
            null,
            'website',
            null,
            null,
            null,
        );
    }

    /**
     * A page with no row of its own, or "page not found": its title only.
     *
     * @param  array<string, string|Closure(): string>  $tokens
     */
    private function plain(string $heading, ?string $kind, array $tokens, ?string $url = null, ?string $row = null): HeadValues
    {
        $tokens['title'] = $heading;
        $title = $row === null
            ? TitleTemplate::fill(TitlesAndListing::ITEM, $tokens, TitlesAndListing::separator())
            : $this->fill($row, $tokens);

        return new HeadValues($title, null, $url, null, RobotsMeta::directives($kind, false), null, null, 'website', null, null, null);
    }

    /**
     * An item's canonical (I-292): the item another names by its live
     * address, made now (rule 9), or an address on another site, or the
     * item's own. The resolver records the named item's tag, so this page is
     * purged when that item moves (I-263).
     *
     * @param  array<string, mixed>  $fields
     */
    private function canonical(string $id, string $own, array $fields): string
    {
        $choice = $fields[BuiltInFields::CANONICAL] ?? null;

        // Before it was a pair, the field held a plain address.
        if (is_string($choice)) {
            $choice = trim($choice) === '' ? [] : ['address' => trim($choice)];
        }

        if (is_array($choice) && is_string($choice['item'] ?? null) && $choice['item'] !== $id) {
            // A resolver for this page alone: one keeps what it found while it lives, and this object outlives a page.
            $path = app(LinkResolver::class)->url($choice['item']);

            return $path === null ? $own : rtrim(url('/'), '/').PublicRequestPath::encode($path);
        }

        if (is_array($choice) && is_string($choice['address'] ?? null) && preg_match(CanonicalChoice::ADDRESS, $choice['address']) === 1) {
            return $choice['address'];
        }

        return $own;
    }

    /**
     * The posts page item's published fields, and the media ID of the first
     * image card in its published body (I-293), read together.
     *
     * @return array{type: string, fields: array<string, mixed>, firstImage: string|null}
     */
    private function stored(string $id): array
    {
        $row = DB::table('contents')
            ->leftJoin('content_revisions', 'content_revisions.id', '=', 'contents.published_revision_id')
            ->where('contents.id', $id)
            ->selectRaw("contents.type, contents.fields, jsonb_path_query_first(content_revisions.document, ?::jsonpath) #>> '{}' AS first_image", ['$.content[*] ? (@.type == "image").attrs.mediaId'])
            ->first();

        return [
            'type' => (string) ($row->type ?? ''),
            'fields' => (array) json_decode((string) ($row->fields ?? '{}'), true),
            'firstImage' => is_string($row->first_image ?? null) && $row->first_image !== '' ? $row->first_image : null,
        ];
    }

    /**
     * The page's social image (I-293, I-855): its own, else its featured
     * image, else the first image in its body, else the site's default; the
     * 1200×630 card once it is made, else the widest size.
     *
     * @param  array<string, mixed>  $fields
     */
    private function image(array $fields, ?string $firstImage): ?SocialImage
    {
        $id = null;
        $alt = null;

        foreach ([BuiltInFields::SOCIAL_IMAGE, BuiltInFields::FEATURED_IMAGE] as $key) {
            $chosen = $fields[$key] ?? null;

            if (is_array($chosen) && is_string($chosen['id'] ?? null) && $chosen['id'] !== '') {
                $id = $chosen['id'];
                $alt = self::text($chosen['alt'] ?? null);

                break;
            }
        }

        $id ??= $firstImage;

        // Last, the site's own (I-293).
        $id ??= SocialCards::defaultImage();

        if ($id === null) {
            return null;
        }

        app(RenderRecord::class)->tag(PageTags::media($id));
        $media = DB::table('media')->where('id', $id)->where('kind', 'image')->whereNull('deleted_at')->first(['id', 'alt', 'variants']);

        if ($media === null) {
            return null;
        }

        $variants = (array) json_decode((string) $media->variants, true);
        $card = is_array($variants['social'] ?? null);
        $variant = $card ? $variants['social'] : null;
        $key = 'social';

        if ($variant === null) {
            $widths = array_filter($variants, fn (mixed $file, string|int $key): bool => is_array($file) && preg_match('/^w\d+$/', (string) $key) === 1, ARRAY_FILTER_USE_BOTH);
            uasort($widths, fn (array $a, array $b): int => (int) ($b['width'] ?? 0) <=> (int) ($a['width'] ?? 0));
            $variant = $widths === [] ? null : reset($widths);
            $key = (string) array_key_first($widths);
        }

        if (! is_array($variant) || ! is_string($variant['path'] ?? null)) {
            return null;
        }

        return new SocialImage(
            (string) $media->id,
            url(app(ImageServices::class)->address((string) $media->id, $key, $variant['path'])),
            (int) $variant['width'],
            (int) $variant['height'],
            $alt ?? self::text($media->alt),
            $card,
        );
    }

    /**
     * `{page}` from a listing's second page on: "Page 2 of 7", or "Page 2"
     * when the total isn't known; '' on the first page.
     */
    private function pageText(Pagination $pagination, bool $capped = false): string
    {
        if ($pagination->current < 2) {
            return '';
        }

        return $pagination->total !== null && ! $capped
            ? __('Page :number of :total', ['number' => $pagination->current, 'total' => $pagination->total])
            : __('Page :number', ['number' => $pagination->current]);
    }

    /**
     * @param  array<string, string|Closure(): string>  $tokens
     */
    private function fill(string $row, array $tokens): string
    {
        return TitleTemplate::fill(TitlesAndListing::template($row), $tokens, TitlesAndListing::separator());
    }

    /**
     * The address being drawn, as PathResolver made it canonical.
     */
    private function here(): string
    {
        return $this->root().PublicRequestPath::encode(rawurldecode(request()->getPathInfo()));
    }

    /**
     * A site-relative address made absolute, or null for none.
     */
    private function absolute(?string $path): ?string
    {
        return $path === null ? null : $this->root().PublicRequestPath::encode($path);
    }

    /**
     * The site's address with no trailing slash, as Addresses::urlFor() builds one.
     */
    private function root(): string
    {
        return rtrim(url('/'), '/');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
