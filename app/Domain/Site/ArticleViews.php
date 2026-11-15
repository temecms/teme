<?php

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Addresses\Addresses;
use App\Domain\Cache\PageTags;
use App\Domain\Cache\RenderRecord;
use App\Domain\Cards\CardUsage;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\RenderContext;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Design\SiteDesign;
use App\Domain\Fields\BuiltInFields;
use App\Domain\People\Credit;
use App\Domain\People\Credits;
use App\Domain\Site\Insertions\InsertionCleaner;
use App\Domain\Site\Insertions\InsertionOrigins;
use App\Domain\Site\Insertions\InsertionPlan;
use App\Domain\Site\Insertions\InsertionPoints;
use App\Domain\Site\Insertions\PlanBuilder;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Site\ViewModels\Crumb;
use App\Domain\Site\ViewModels\ItemLink;
use App\Domain\Site\ViewModels\TermLink;
use App\Domain\Taxonomy\PrimaryCategory;
use App\Domain\Types\ContentTypeRegistry;
use Teme\Contracts\Insertions\ArticleFacts;
use Teme\Contracts\Insertions\Insertion;

/**
 * Articles as their templates see them (I-250): built from the published
 * revision, or, for a preview, from the revision shared, with the dates to
 * show in the article's language, the reading time, the lead image decided
 * exactly, the body rendered, its kicker and tags, and its related posts.
 */
final readonly class ArticleViews
{
    /** A revision this much newer than the first publication shows as updated. */
    private const int UPDATED_AFTER_HOURS = 24;

    public function __construct(
        private ListingViews $listings,
        private LeadImage $leads,
        private DocumentRenderer $renderer,
        private SiteLanguage $language,
        private RelatedPosts $related,
        private SiteDesign $design,
        private Breadcrumbs $trail,
        private FieldViews $fieldViews,
        private PageFamily $family,
        private ContentTypeRegistry $types,
    ) {}

    /**
     * The article as readers see it: its published revision.
     */
    public function published(Content $content): ArticleView
    {
        return $this->build($content, ContentRevision::query()->findOrFail($content->published_revision_id), true, true);
    }

    /**
     * The article as one of its revisions would look, for a preview. It
     * takes IDs: the preview controller may not hold a model (LayeringTest).
     */
    public function ofRevision(string $contentId, string $revisionId): ArticleView
    {
        $content = Content::withTrashed()->findOrFail($contentId);

        // A preview is of a revision; comments belong to the published item (D20).
        return $this->build($content, ContentRevision::query()->where('content_id', $contentId)->findOrFail($revisionId), false, false);
    }

    /**
     * The item's published title and kicker, as its article shows them, for
     * the pages about it: a later page of its comments, core's answers to a
     * comment posted on it.
     *
     * @return array{title: string, kicker: TermLink|null}
     */
    public function headline(Content $content): array
    {
        $revision = ContentRevision::query()->findOrFail($content->published_revision_id);

        return ['title' => (string) $revision->title, 'kicker' => $this->terms((string) $content->type, array_map('strval', (array) $revision->terms), ((array) $revision->fields)[BuiltInFields::PRIMARY_CATEGORY] ?? null)[0]];
    }

    private function build(Content $content, ContentRevision $revision, bool $withComments, bool $drawInsertions): ArticleView
    {
        app(RenderRecord::class)->tag(PageTags::content((string) $content->id));
        $fields = (array) $revision->fields;
        $document = (array) $revision->document;
        $language = $this->language->forContent($content);

        // No featured lead when the theme's featured_image_style is hidden.
        $lead = $this->leads->for($content, $document, $this->design->showsFeaturedImage(), $fields);
        // One read of the credits serves the byline, the comments' author mark and insertion facts (I-3366, I-064).
        $credits = app(Credits::class)->shown([(string) $content->id])[(string) $content->id] ?? [];
        $plan = $this->insertionPlan($content, $revision, $document, $language, $credits, $fields, $drawInsertions);
        $body = $this->renderer->html($document, new RenderContext(
            app(LinkResolver::class),
            usage: app(CardUsage::class),
            leadNodeId: $lead['nodeId'],
            insertions: $plan,
        ));

        // A draft never published has no first publication yet: the revision's own time stands in.
        $published = SiteDates::inSite($content->published_at ?? $revision->created_at);
        $saved = SiteDates::inSite($revision->created_at);
        $updated = $saved->greaterThanOrEqualTo($published->addHours(self::UPDATED_AFTER_HOURS)) ? $saved : null;

        [$kicker, $tags, $section] = $this->terms((string) $content->type, array_map('strval', (array) $revision->terms), $fields[BuiltInFields::PRIMARY_CATEGORY] ?? null);
        $url = Addresses::relativeUrl($content) ?? '';
        [$ancestors, $parent, $children] = $this->family($content);

        return new ArticleView(
            id: (string) $content->id,
            type: (string) $content->type,
            title: (string) $revision->title,
            url: $url,
            template: self::text($fields[BuiltInFields::TEMPLATE] ?? null),
            standfirst: self::text($fields[BuiltInFields::EXCERPT] ?? null),
            kicker: $kicker,
            authors: $this->listings->people($credits),
            published: SiteDates::forView($published),
            updated: $updated === null ? null : SiteDates::forView($updated),
            publishedText: SiteDates::show($published, $language),
            updatedText: $updated === null ? null : SiteDates::show($updated, $language),
            readingMinutes: ReadingTime::minutes((int) $revision->word_count),
            lead: $lead['ref'],
            leadIsFeatured: $lead['ref'] !== null && $lead['nodeId'] === null,
            bodyHtml: $body,
            tags: $tags,
            related: $this->related->for($content),
            comments: $withComments ? app(CommentViews::class)->forItem($content, 1, $url, array_values(array_map(fn (object $person): int => (int) $person->id, array_filter($credits, fn (object $person): bool => $person->kind === Credit::ACCOUNT)))) : null,
            revisionId: (string) $revision->id,
            fields: $fields,
            firstImageId: self::firstImage($document),
            wordCount: (int) $revision->word_count,
            revised: SiteDates::forView($saved),
            breadcrumbs: ! $this->trail->wanted() ? null : ($url === '/' ? [$this->trail->home()] : $this->trail->toItem((string) $revision->title, $url, $section, array_map(fn (ItemLink $link): Crumb => new Crumb($link->title, $link->url), $ancestors))),
            fieldViews: $this->fieldViews->forArticle((string) $content->type, (string) $content->id, $fields),
            parent: $parent,
            ancestors: $ancestors,
            children: $children,
        );
    }

    /**
     * The cleaned insertion plan for a published article page, or null when
     * previews, opt-out, or no contributor gave anything (I-064).
     *
     * @param  array<mixed>  $document
     * @param  list<object>  $credits
     * @param  array<string, mixed>  $fields
     */
    private function insertionPlan(Content $content, ContentRevision $revision, array $document, string $language, array $credits, array $fields, bool $drawInsertions): ?InsertionPlan
    {
        if (! $drawInsertions || ($fields[BuiltInFields::INSERTIONS_OFF] ?? false) === true) {
            return null;
        }

        // Tagged before any contributor is asked, drawn or not: a plugin enabled, or rules changed, later purges it too.
        app(RenderRecord::class)->tag(PageTags::INSERTIONS);

        $termIds = array_map('strval', (array) $revision->terms);
        $byTaxonomy = [];

        foreach ($this->listings->termLinks($termIds) as $id => $link) {
            $byTaxonomy[$link->taxonomy][] = $id;
        }

        $paragraphs = 0;

        foreach ((array) ($document['content'] ?? []) as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'paragraph') {
                $paragraphs++;
            }
        }

        $facts = new ArticleFacts(
            contentId: (string) $content->id,
            type: (string) $content->type,
            termIdsByTaxonomy: $byTaxonomy,
            language: $language,
            authorIds: array_map(fn (object $person): string => (string) $person->id, $credits),
            words: (int) $revision->word_count,
            paragraphs: $paragraphs,
        );

        $cleaned = [];
        $origins = [];

        foreach (app(InsertionPoints::class)->for($facts) as $insertion) {
            $result = InsertionCleaner::clean($insertion->html);
            $cleaned[] = new Insertion($insertion->key, $insertion->at, $result['html']);

            foreach ($result['origins'] as $directive => $hosts) {
                $origins[$directive] = array_values(array_unique([...($origins[$directive] ?? []), ...$hosts]));
            }
        }

        if ($cleaned === []) {
            return null;
        }

        $plan = PlanBuilder::for($document, $facts, $cleaned);

        if ($plan->empty()) {
            return null;
        }

        // Origins wait for PublicPage to start the policy; PathResolver builds the article first.
        app(InsertionOrigins::class)->add($origins);

        return new InsertionPlan($plan->at(), $origins);
    }

    /**
     * The article's kicker, its primary category (I-869), its tags, the
     * terms in its type's flat taxonomies in the revision's order, and the
     * kicker's term ID, for its trail; terms deleted since are left out.
     *
     * @param  list<string>  $termIds
     *
     * @return array{0: TermLink|null, 1: list<TermLink>, 2: string|null}
     */
    private function terms(string $type, array $termIds, mixed $primary): array
    {
        $links = $this->listings->termLinks($termIds);
        $kickerTaxonomy = $this->listings->kickerTaxonomy($type);
        $flat = $this->listings->flatTaxonomies($type);
        $sections = [];
        $tags = [];

        foreach ($termIds as $id) {
            $link = $links[$id] ?? null;

            if ($link === null) {
                continue;
            }

            if ($link->taxonomy === $kickerTaxonomy) {
                $sections[] = $id;
            } elseif (in_array($link->taxonomy, $flat, true)) {
                $tags[] = $link;
            }
        }

        $kicker = PrimaryCategory::pick($sections, $primary);

        return [$kicker === null ? null : $links[$kicker], $tags, $kicker];
    }

    /**
     * The live items above it, the top first, its parent among them while
     * readers can see it, and those under it, each read once and only for a
     * type that nests (I-3363).
     *
     * @return array{0: list<ItemLink>, 1: ItemLink|null, 2: list<ItemLink>}
     */
    private function family(Content $content): array
    {
        if (! $this->types->has((string) $content->type) || ! $this->types->get((string) $content->type)->hierarchical) {
            return [[], null, []];
        }

        $id = (string) $content->id;
        [$ancestors, $parent] = $content->parent_id === null ? [[], null] : $this->family->lineage($id);

        return [$ancestors, $parent, $this->family->children($id)];
    }

    /**
     * The media of the first image card at the body's top level, as the
     * head's image falls back to it (I-293), or null.
     *
     * @param  array<string, mixed>  $document
     */
    private static function firstImage(array $document): ?string
    {
        foreach ((array) ($document['content'] ?? []) as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'image' && is_string($node['attrs']['mediaId'] ?? null) && $node['attrs']['mediaId'] !== '') {
                return $node['attrs']['mediaId'];
            }
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
