<?php

/**
 * The posts and pages phase (I-335): each WordPress post, page and reusable
 * block, converted before its transaction, then written as WordPress had it
 * through ImportContent, or brought up to date through UpdateImportedContent
 * while nobody changed it here. Its map rows keep what comments, menus, the
 * check and the second pass read.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Content;

use App\Domain\Addresses\AddressPattern;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Paths;
use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\ImportedItem;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ImportContent;
use App\Domain\Contents\Operations\Input\ImportContentInput;
use App\Domain\Contents\Operations\Input\UpdateImportedContentInput;
use App\Domain\Contents\Operations\UpdateImportedContent;
use App\Domain\Contents\Slugs;
use App\Domain\Contents\Tree\SubtreeWords;
use App\Domain\Contents\Visibility;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Import\Convert\BlockConverter;
use App\Domain\Import\Convert\Conversion;
use App\Domain\Import\Convert\ConversionItem;
use App\Domain\Import\Convert\ConversionSite;
use App\Domain\Import\Convert\PendingReference;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportFailed;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ImportStopped;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\Media\ImportedMedia;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\PreparesObjects;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Translations\ImportedLanguages;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Taxonomy\TermRules;
use App\Domain\Themes\TemplateChoice;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Teme\Contracts\Settings\SettingsReader;

final class ContentPhase implements ListPhase, PreparesObjects
{
    /** Items a page: the companion's own default without rendered pages. */
    public const int PAGE = 50;

    /** WordPress's reusable blocks, which the second pass inlines (I-333). */
    public const string REUSABLE = 'wp_block';

    /** The most terms one item keeps, as CreateDraft allows. */
    public const int MOST_TERMS = 50;

    /** The free slugs tried before an item fails. */
    public const int SLUG_TRIES = 20;

    /** The statuses the list asks for; the companion sends the trash once it can (I-1709). */
    private const string STATUSES = 'publish,future,draft,pending,private,trash';

    /** What the converter says of media Teme hasn't got yet. */
    private const array MISSING = ['image.missing', 'media.missing', 'file.missing'];

    /** The fields that hold an image, with their names in a message. */
    private const array IMAGES = [[BuiltInFields::FEATURED_IMAGE, 'featured image'], [BuiltInFields::SOCIAL_IMAGE, 'social image']];

    /** The old site's addresses, once a page job. */
    private ?ConversionSite $site = null;

    public function __construct(
        private readonly ImportMap $map,
        private readonly BlockConverter $converter,
        private readonly ContentSite $sites,
        private readonly ContentTypeRegistry $types,
        private readonly TaxonomyRegistry $taxonomies,
        private readonly FieldRegistry $fields,
        private readonly SettingsReader $settings,
    ) {}

    public function key(): string
    {
        return 'content';
    }

    public function order(): int
    {
        return 500;
    }

    public function label(): string
    {
        return 'Posts and pages';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function lists(ImportContext $context): array
    {
        $types = [];

        foreach ($this->types->all() as $type) {
            if ($type->listed && $type->wordpress !== null) {
                $types[] = $type->wordpress;
            }
        }

        $query = ['types' => implode(',', [...$types, self::REUSABLE]), 'statuses' => self::STATUSES];

        if ($context->modifiedAfter !== null) {
            $query['modified_after'] = $context->modifiedAfter->utc()->format('Y-m-d H:i:s');
        }

        return [new ImportList('content', $query, self::PAGE, ['comment_count'])];
    }

    public function identify(ImportList $list, array $item, int|string $key): SourceRef
    {
        return SourceRef::of('post', (int) $item['id']);
    }

    /**
     * The item's words converted, outside its transaction (the converter is
     * pure, and reads the map's targets only); nothing for an item that
     * won't be converted.
     */
    public function prepare(SourceRef $ref, array $item, ImportContext $context): PreparedObject
    {
        if ($this->typeOf($item) === null && ($item['type'] ?? null) !== self::REUSABLE) {
            return new PreparedContent(null, null);
        }

        $this->site ??= $this->sites->for($context->sourceId);
        $blocks = array_values(array_filter(is_array($item['blocks'] ?? null) ? $item['blocks'] : [], is_array(...)));

        return new PreparedContent($this->converter->convert(
            $blocks,
            $this->site,
            new ImportedMedia($context->sourceId),
            new MapTargets($context->sourceId),
            new ConversionItem((int) $ref->id, self::shortcodes($item)),
        ), $this->site);
    }

    public function import(SourceRef $ref, array $item, ImportContext $context, ?PreparedObject $prepared): ImportResult
    {
        $conversion = $prepared instanceof PreparedContent ? $prepared->conversion : null;
        $site = $prepared instanceof PreparedContent ? $prepared->site : null;

        if (($item['type'] ?? null) === self::REUSABLE) {
            return $this->reusable($conversion);
        }

        $type = $this->typeOf($item);

        if ($type === null) {
            $name = mb_substr((string) ($item['type'] ?? ''), 0, 40);

            return ImportResult::skipped(new ImportMessage('post.other-type', "It’s a {$name}, which Teme doesn’t import yet.", ['type' => $name]));
        }

        $state = WordPressPost::status($item);

        if ($state === null) {
            $status = mb_substr((string) ($item['status'] ?? ''), 0, 40);

            return ImportResult::skipped(new ImportMessage('post.status-skipped', "Its status on WordPress, {$status}, isn’t one Teme imports, so it wasn’t imported.", ['status' => $status]));
        }

        if ($conversion === null || $site === null) {
            throw new LogicException('The content phase converts every post and page in prepare().');
        }

        $entry = $this->map->entry($context->sourceId, $ref);

        return $entry?->teme_id !== null
            ? $this->again($ref, $entry, $item, $type, $state, $conversion, $site, $context)
            : $this->create($ref, $item, $type, $state, $conversion, $site, $context);
    }

    /**
     * A post or page met for the first time, written through ImportContent.
     *
     * @param  array<string, mixed>  $item
     * @param  array{status: string, visibility: string|null, trashed: bool, dropped: bool}  $state
     */
    private function create(SourceRef $ref, array $item, ContentType $type, array $state, Conversion $conversion, ConversionSite $site, ImportContext $context): ImportResult
    {
        $id = strtolower((string) Str::ulid());
        // Created in its language at its old address, linked to its translations at the end (I-3446).
        $language = app(ImportedLanguages::class)->stored($context->sourceId, $item['language'] ?? null, app(SiteLanguages::class)->translates($type->key));

        if ($language === false) {
            return ImportResult::skipped(new ImportMessage('post.language-missing', __('Its language on the old site isn’t one of this site’s, so it wasn’t imported. The Languages step of the report says why.')));
        }

        [$decided, $notes, $extra] = $this->decide($ref, $item, $type, $state, $conversion, $site, $context, $language);
        [$decided, $slugNotes] = $this->freeSlug($decided, $id, $context);
        $written = app(ImportContent::class)->execute(new ImportContentInput($id, $decided, $context->actorId()));

        return $this->result(ImportResult::created('content', $written['contentId']), $item, $state, $conversion, $this->placedLater($type, $item) ? [...$written, 'moved' => null] : $written, [...$notes, ...$slugNotes], $extra);
    }

    /**
     * A post or page an earlier run made: left as it is when it was deleted,
     * trashed or changed here, or is another type now; else brought up to
     * date through UpdateImportedContent.
     *
     * @param  array<string, mixed>  $item
     * @param  array{status: string, visibility: string|null, trashed: bool, dropped: bool}  $state
     */
    private function again(SourceRef $ref, ImportMapEntry $entry, array $item, ContentType $type, array $state, Conversion $conversion, ConversionSite $site, ImportContext $context): ImportResult
    {
        $contentId = (string) $entry->teme_id;
        $content = Content::withTrashed()->find($contentId);

        if ($content === null) {
            return ImportResult::skipped(new ImportMessage('post.deleted-here', 'It was imported before and has since been deleted in Teme, so it wasn’t imported again.'));
        }

        $updated = ImportResult::updated('content', $contentId);

        if ($content->type !== $type->key) {
            $name = mb_substr((string) ($item['type'] ?? ''), 0, 40);

            return $updated->withWarning(new ImportMessage('post.type-changed', "It became a {$name} on WordPress since the last import. Teme can’t change an item’s type, so it stays as it was here.", ['type' => $name]));
        }

        $record = is_string($entry->facts['written'] ?? null) ? $entry->facts['written'] : '';

        // Before anything is decided: a person's change here is never refused, only kept. An item the
        // import itself trashed (its fingerprint unmoved) comes out when WordPress restores it.
        if ($record !== ImportedContents::fingerprint($content)) {
            return $updated->withWarning($content->trashed() && ! $state['trashed']
                ? new ImportMessage('post.trashed-here', 'It’s in Teme’s trash, so the import left it there as it was.')
                : self::changedHere());
        }

        [$decided, $notes, $extra] = $this->decide($ref, $item, $type, $state, $conversion, $site, $context, $content->language);
        [$decided, $slugNotes] = $this->freeSlug($decided, $contentId, $context);
        $written = app(UpdateImportedContent::class)->execute(new UpdateImportedContentInput($contentId, $record, $decided, $context->actorId()));

        if ($written['changedHere']) {
            return $updated->withWarning(self::changedHere());
        }

        return $this->result($updated, $item, $state, $conversion, $this->placedLater($type, $item) ? [...$written, 'moved' => null] : $written, [...$notes, ...$slugNotes], $extra);
    }

    /**
     * A reusable block: no item, its converted content kept on its map row
     * for the second pass to inline (I-333).
     */
    private function reusable(?Conversion $conversion): ImportResult
    {
        if ($conversion === null) {
            throw new LogicException('The content phase converts every reusable block in prepare().');
        }

        $result = ImportResult::skipped(new ImportMessage('post.reusable-block', 'It’s a reusable block: Teme puts its content into each post and page that uses it.'));

        foreach ($conversion->messages as $message) {
            $result = $result->withWarning($message);
        }

        return $result->withFacts([
            'kind' => 'reusable',
            'document' => $conversion->document['content'],
            'path' => null,
            'parent' => 0,
            'menu_order' => 0,
            'written' => null,
            'references' => array_map(fn (PendingReference $reference): array => $reference->toArray(), $conversion->references),
            'pending_links' => $conversion->pendingLinks,
            'pending_canonical' => null,
            'missing' => self::lacksMedia($conversion),
            'visibility' => null,
            'language' => null,
            'translations' => null,
        ]);
    }

    /**
     * The item as WordPress had it, in Teme's terms; what to say of it; and
     * what its map row keeps beside it.
     *
     * @param  array<string, mixed>  $item
     * @param  array{status: string, visibility: string|null, trashed: bool, dropped: bool}  $state
     *
     * @return array{0: ImportedItem, 1: list<ImportMessage>, 2: array{pending_canonical: string|null, missing: bool}}
     *
     * @throws ImportFailed when nobody can own it
     */
    private function decide(SourceRef $ref, array $item, ContentType $type, array $state, Conversion $conversion, ConversionSite $site, ImportContext $context, ?string $language): array
    {
        $now = CarbonImmutable::now('UTC');
        $title = WordPressPost::title($item);
        $slug = WordPressPost::slug($item, $title['title']);
        $dates = WordPressPost::dates($item, $this->settings->string('general', 'timezone'), $now);
        $path = WordPressPost::path($item);
        $supported = array_map(fn (Field $field): string => $field->key, $this->fields->forType($type->key));
        [$authorId, $notes] = $this->author($item, $context);
        $terms = $this->terms($item, $type, $context, $language);
        $seo = (new WordPressSeo($site, new MapTargets($context->sourceId), new ImportedMedia($context->sourceId)))
            ->read($item['seo'] ?? null, (int) $ref->id, $path, $supported, $terms['primaryTaxonomy']);
        $fields = $seo['fields'];
        $shortened = $title['shortened'] ? ['title'] : [];
        $missing = $seo['missingImage'] || self::lacksMedia($conversion);
        $notes = [...$notes, ...$terms['messages'], ...$seo['messages']];

        if ($title['untitled']) {
            $notes[] = new ImportMessage('post.untitled', 'It had no title on WordPress, so it’s called “Untitled” here.');
        }

        $excerpt = WordPressPost::excerpt($item);

        if ($excerpt['text'] !== null && in_array(BuiltInFields::EXCERPT, $supported, true)) {
            $fields[BuiltInFields::EXCERPT] = $excerpt['text'];
            $shortened = [...$shortened, ...($excerpt['shortened'] ? ['excerpt'] : [])];
        }

        $featured = self::number($item['featured_media'] ?? null);

        if ($featured > 0 && in_array(BuiltInFields::FEATURED_IMAGE, $supported, true)) {
            $mediaId = (new ImportedMedia($context->sourceId))->attachment($featured);

            if ($mediaId !== null) {
                $fields[BuiltInFields::FEATURED_IMAGE] = ['id' => $mediaId, 'alt' => null, 'decorative' => false];
            } else {
                $missing = true;

                // A file's media kept on the old site as links: the address check says so once for every post (CheckStep).
                if (! ($context->readsFile() && $context->choice('media') === 'links')) {
                    $notes[] = new ImportMessage('post.featured-missing', 'Its featured image isn’t in Teme’s library, so it has none for now. A later import sets it if the image comes across.');
                }
            }
        }

        [$fields, $imageNotes] = self::images($fields);
        $notes = [...$notes, ...$imageNotes];

        if (in_array(BuiltInFields::TEMPLATE, $supported, true)) {
            [$template, $templateNote] = self::template($item, $type->key, $context);

            if ($template !== '') {
                $fields[BuiltInFields::TEMPLATE] = $template;
            }

            if ($templateNote !== null) {
                $notes[] = $templateNote;
            }
        }

        if (($item['sticky'] ?? null) === true && in_array(BuiltInFields::STICKY, $supported, true)) {
            $fields[BuiltInFields::STICKY] = true;
        }

        $byTermId = $terms['byTermId'];

        if ($byTermId !== [] && in_array(BuiltInFields::PRIMARY_CATEGORY, $supported, true)) {
            $chosen = $seo['primaryTermId'];
            $fields[BuiltInFields::PRIMARY_CATEGORY] = $chosen !== null && isset($byTermId[$chosen]) ? $byTermId[$chosen] : $byTermId[min(array_keys($byTermId))];
        }

        $shortened = [...$shortened, ...$seo['shortened']];

        if ($shortened !== []) {
            $listed = WordPressPost::listed($shortened);
            $notes[] = count($shortened) === 1
                ? new ImportMessage('post.shortened', "Its {$listed} was longer than Teme keeps, so it was cut short.", ['fields' => $shortened])
                : new ImportMessage('post.shortened', "Its {$listed} were longer than Teme keeps, so they were cut short.", ['fields' => $shortened]);
        }

        $published = $dates['published'] ?? (in_array($state['status'], ['published', 'scheduled'], true) ? $dates['created'] : null);
        $notes = [...$notes, ...self::stateNotes($state, $published, $now)];

        $decided = new ImportedItem(
            type: $type->key,
            title: $title['title'],
            slug: $slug,
            status: $state['status'],
            publishedAt: $published,
            createdAt: $dates['created'],
            modifiedAt: $dates['modified'],
            trashedAt: WordPressPost::trashedAt($item, $now),
            authorId: $authorId,
            schedulerId: $state['status'] === 'scheduled' ? $this->scheduler($authorId, $type) : null,
            discussion: WordPressPost::discussion($item),
            document: $conversion->document,
            fields: $fields,
            terms: $terms['terms'],
            guid: WordPressPost::guid($item),
            wordpressPath: $path,
            oldSlugs: WordPressPost::oldSlugs($item, $slug),
            // Its own local date too: WordPress finds an old slug by the local post_date, which Teme's may not be.
            oldDates: array_values(array_unique([...WordPressPost::oldDates($item), ...WordPressPost::oldDates(['old_dates' => [$item['date'] ?? '']])])),
            visibility: Visibility::from($state['visibility'] ?? 'public'),
            password: WordPressPost::password($item),
            language: $language,
        );

        return [$decided, $notes, ['pending_canonical' => $seo['pendingCanonical'], 'missing' => $missing]];
    }

    /**
     * The result with what was said and what the map keeps: the
     * converter's messages, the decisions' notes, the addresses' outcome,
     * and every fact, each key written every time, since the map merges
     * facts at the top level.
     *
     * @param  array<string, mixed>  $item
     * @param  array{status: string, visibility: string|null, trashed: bool, dropped: bool}  $state
     * @param  array{written: string, moved: array{from: string, to: string}|null, taken: int, many: bool, clash: array{from: string, holder: string}|null, links: list<array{from: string, to: string|null}>}  $written
     * @param  list<ImportMessage>  $notes
     * @param  array{pending_canonical: string|null, missing: bool}  $extra
     */
    private function result(ImportResult $result, array $item, array $state, Conversion $conversion, array $written, array $notes, array $extra): ImportResult
    {
        foreach ([...$conversion->messages, ...$notes, ...self::addressNotes($written)] as $message) {
            $result = $result->withWarning($message);
        }

        // A path the normaliser turned into an ID on save is no work for the second pass (I-1669).
        $resolved = [];

        foreach ($written['links'] as $link) {
            if (is_string($link['to']) && str_starts_with($link['to'], 'teme:')) {
                $resolved[] = $link['from'];
            }
        }

        return $result->withFacts([
            'path' => WordPressPost::path($item),
            'parent' => max(0, self::number($item['parent'] ?? null)),
            'menu_order' => self::number($item['menu_order'] ?? null),
            'written' => $written['written'],
            'references' => array_map(fn (PendingReference $reference): array => $reference->toArray(), $conversion->references),
            'pending_links' => array_values(array_diff($conversion->pendingLinks, $resolved)),
            'pending_canonical' => $extra['pending_canonical'],
            'missing' => $extra['missing'],
            'visibility' => $state['visibility'],
            'language' => is_string($item['language'] ?? null) ? $item['language'] : null,
            'translations' => is_array($item['translations'] ?? null) ? $item['translations'] : null,
        ]);
    }

    /**
     * The item under a slug and an address free for it: its own, else the
     * next free one, {slug}-2 to {slug}-20, while the slug is taken in its
     * type or its address is the site's own or another's. Only an address
     * both sites claim stops the run (I-1706): WordPress's own address, which
     * Teme's pattern gives it too, held by something this source didn't make.
     *
     * @return array{0: ImportedItem, 1: list<ImportMessage>}
     *
     * @throws ImportStopped
     * @throws ImportFailed
     */
    private function freeSlug(ImportedItem $item, string $contentId, ImportContext $context): array
    {
        $base = $item->slug;

        for ($try = 1; $try <= self::SLUG_TRIES; $try++) {
            $suffix = $try === 1 ? '' : "-{$try}";
            $candidate = $item->withSlug(rtrim(mb_substr($base, 0, 200 - strlen($suffix)), '-').$suffix);
            $address = ImportedContents::address($contentId, $candidate);
            $holder = $address === null ? null : DB::table('paths')->where('path', $address)->where('is_current', true)->first(['target_type', 'target_id']);
            $mine = $holder !== null && $holder->target_type === 'content' && (string) $holder->target_id === $contentId;
            $here = $holder !== null && ! $mine && $this->madeHere($context->sourceId, (string) $holder->target_type, (string) $holder->target_id);

            if ($try === 1 && $holder !== null && ! $mine && ! $here && $address === $item->wordpressPath) {
                $what = Paths::describe((string) $holder->target_type, (string) $holder->target_id);

                throw ImportStopped::because('address-clash', "Teme already has {$what} at /{$address}/, and “{$item->title}” from WordPress needs that address too, so the import stopped. Change one of the two addresses, then carry on the import.");
            }

            $slugFree = $candidate->trashedAt !== null || Slugs::isAvailable($candidate->type, $candidate->slug, $contentId, $candidate->language);
            // Another language's address is checked after its prefix (I-3360).
            $segments = explode('/', (string) $address);
            $addressFree = $address === null || ($holder === null || $mine) && ! Paths::taken($segments[$candidate->language !== null && count($segments) > 1 ? 1 : 0]);

            if ($slugFree && $addressFree) {
                return [$candidate, $try === 1 ? [] : [new ImportMessage('post.slug-changed', "Its address name “{$base}” is taken here, so it became “{$candidate->slug}”.", ['from' => $base, 'to' => $candidate->slug])]];
            }
        }

        throw new ImportFailed(ImportMessage::error('post.no-free-address', 'Teme couldn’t find a free address for it in 20 tries, so it wasn’t imported. Change its address name on WordPress or here, then run a full import.'));
    }

    /**
     * The person it belongs to, saying so when it isn't WordPress's author:
     * the author through the map, still here; else the owner the choices
     * name; else the run's actor; else the first administrator by ID.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array{0: string, 1: list<ImportMessage>}
     *
     * @throws ImportFailed
     */
    private function author(array $item, ImportContext $context): array
    {
        $wordpress = self::number($item['author'] ?? null);
        // An export file numbers the authors it names only on its items below zero.
        $mapped = $wordpress !== 0 ? $this->map->temeId($context->sourceId, SourceRef::of('user', $wordpress)) : null;

        if ($mapped !== null && User::query()->whereKey($mapped)->exists()) {
            return [$mapped, []];
        }

        $missing = new ImportMessage('post.author-missing', 'Its author on WordPress didn’t come across as a person here, so it’s credited to the person you chose under Items whose author is gone.');

        foreach ([$context->choice('owner'), $context->actorId()] as $candidate) {
            if (is_string($candidate) && ctype_digit($candidate) && User::query()->whereKey($candidate)->exists()) {
                return [$candidate, [$missing]];
            }
        }

        $first = User::role('administrator')->orderBy('id')->value('id');

        if ($first === null) {
            throw new ImportFailed(ImportMessage::error('post.no-owner', 'Its author on WordPress didn’t come across, and Teme has no administrator to own it, so it wasn’t imported.'));
        }

        return [(string) $first, [$missing]];
    }

    /**
     * The item's terms in WordPress's order (each Teme taxonomy of its type
     * taking WordPress's terms of its WordPress name, through the map, still
     * here and ones the item may be filed under, at most 50; a term of
     * another language is left off); its categories
     * by WordPress's term_id, for the primary category; the WordPress name
     * of the type's first hierarchical taxonomy; and what to say.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array{terms: list<string>, byTermId: array<int, string>, primaryTaxonomy: string|null, messages: list<ImportMessage>}
     */
    private function terms(array $item, ContentType $type, ImportContext $context, ?string $language): array
    {
        $sent = is_array($item['terms'] ?? null) ? $item['terms'] : [];
        $wanted = [];
        $kept = [];
        $primaryTaxonomy = null;

        foreach ($type->taxonomies as $key) {
            $taxonomy = $this->taxonomies->get($key);

            if ($taxonomy->wordpress === null) {
                continue;
            }

            $kept[] = $taxonomy->wordpress;
            $primaryTaxonomy ??= $taxonomy->hierarchical ? $taxonomy->wordpress : null;

            foreach (is_array($sent[$taxonomy->wordpress] ?? null) ? $sent[$taxonomy->wordpress] : [] as $value) {
                $number = self::number($value);

                // An export file numbers the terms it names only on its items below zero.
                if ($number !== 0) {
                    $wanted[] = [$taxonomy->wordpress, $number];
                }
            }
        }

        /** @var array<int|string, ImportMapEntry> $rows */
        $rows = [];

        foreach (ImportMapEntry::query()->where('source_id', $context->sourceId)->where('object_type', 'term')->whereNotNull('teme_id')
            ->whereIn('object_id', array_map(fn (array $pair): string => (string) $pair[1], $wanted))->get(['object_id', 'teme_id', 'facts']) as $row) {
            $rows[(string) $row->object_id] = $row;
        }

        $temeIds = array_values(array_map(fn (ImportMapEntry $row): string => (string) $row->teme_id, $rows));
        // Each of those terms still here, with its taxonomy here.
        $stored = DB::table('terms')->whereIn('id', $temeIds)->get(['id', 'taxonomy'])->keyBy(fn (object $term): string => (string) $term->id);
        // Only terms the item may be filed under, so a term the save would refuse never fails the item.
        $here = TermRules::usableAmong($temeIds, $type->key, $language);
        $terms = [];
        // The primary taxonomy's terms by WordPress's term_id, for the primary category (I-869).
        $byTermId = [];
        $missing = 0;
        $otherLanguage = 0;

        foreach ($wanted as [$wordpress, $number]) {
            $row = $rows[(string) $number] ?? null;
            $termId = $row === null ? null : (string) $row->teme_id;

            if ($termId === null || ! in_array($termId, $here, true)) {
                // Here, in a taxonomy of its type, yet refused: it is another language's.
                if ($termId !== null && in_array($stored->get($termId)?->taxonomy, $type->taxonomies, true)) {
                    $otherLanguage++;
                } else {
                    $missing++;
                }

                continue;
            }

            if (in_array($termId, $terms, true)) {
                continue;
            }

            $terms[] = $termId;

            if ($wordpress === $primaryTaxonomy) {
                $byTermId[self::number($row->facts['term_id'] ?? null)] = $termId;
            }
        }

        $messages = [];

        if ($missing > 0) {
            $messages[] = $missing === 1
                ? new ImportMessage('post.terms-missing', 'One of its categories and tags didn’t come across, so it isn’t filed under it here.', ['count' => 1])
                : new ImportMessage('post.terms-missing', "{$missing} of its categories and tags didn’t come across, so it isn’t filed under them here.", ['count' => $missing]);
        }

        if ($otherLanguage > 0) {
            $messages[] = $otherLanguage === 1
                ? new ImportMessage('post.terms-other-language', __('One of its categories and tags is in another language here, so it was left off.'), ['count' => 1])
                : new ImportMessage('post.terms-other-language', __(':count of its categories and tags are in another language here, so they were left off.', ['count' => $otherLanguage]), ['count' => $otherLanguage]);
        }

        if (count($terms) > self::MOST_TERMS) {
            $count = count($terms);
            $messages[] = new ImportMessage('post.terms-many', "It had {$count} categories and tags on WordPress, more than the 50 Teme keeps on one item, so only the first 50 came across.", ['count' => $count]);
            $terms = array_slice($terms, 0, self::MOST_TERMS);
            $byTermId = array_filter($byTermId, fn (string $termId): bool => in_array($termId, $terms, true));
        }

        $left = array_values(array_diff(array_map(strval(...), array_keys($sent)), $kept));

        if ($left !== []) {
            $names = WordPressPost::listed(array_map(fn (string $name): string => mb_substr($name, 0, 40), array_slice($left, 0, 5)));
            $plural = mb_strtolower($type->plural);
            $messages[] = new ImportMessage('post.terms-left', "It’s filed under {$names} on WordPress, which Teme’s {$plural} don’t have, so it isn’t filed under those here.", ['taxonomies' => array_slice($left, 0, 5)]);
        }

        return ['terms' => $terms, 'byTermId' => $byTermId, 'primaryTaxonomy' => $primaryTaxonomy, 'messages' => $messages];
    }

    /**
     * Who Teme's scheduler publishes a scheduled item as: its author, when
     * they may publish its type, else the first administrator by ID.
     */
    private function scheduler(string $authorId, ContentType $type): ?string
    {
        if (User::query()->find($authorId)?->can($type->ability('publish')) === true) {
            return $authorId;
        }

        $first = User::role('administrator')->orderBy('id')->value('id');

        return $first === null ? null : (string) $first;
    }

    /**
     * Whether this source made the item, term or person at an address.
     */
    private function madeHere(string $sourceId, string $type, string $id): bool
    {
        return ImportMapEntry::query()->where('teme_type', $type)->where('teme_id', $id)->where('source_id', $sourceId)->exists();
    }

    /**
     * The type whose WordPress name is the item's, or null.
     *
     * @param  array<string, mixed>  $item
     */
    private function typeOf(array $item): ?ContentType
    {
        foreach ($this->types->all() as $type) {
            if ($type->listed && $type->wordpress !== null && $type->wordpress === ($item['type'] ?? null)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The template's key through the choices, '' for the theme's default,
     * and what to say when the choices don't match one the theme offers.
     *
     * @param  array<string, mixed>  $item
     *
     * @return array{0: string, 1: ImportMessage|null}
     */
    private static function template(array $item, string $type, ImportContext $context): array
    {
        $file = is_string($item['template'] ?? null) ? trim($item['template']) : '';

        if ($file === '' || $file === 'default') {
            return ['', null];
        }

        $choices = $context->choice('templates', []);
        $choices = is_array($choices) ? $choices : [];

        // The person chose the theme's default for it.
        if (array_key_exists($file, $choices) && $choices[$file] === null) {
            return ['', null];
        }

        $chosen = is_string($choices[$file] ?? null) ? $choices[$file] : '';

        if ($chosen !== '' && array_key_exists($chosen, TemplateChoice::declared($type))) {
            return [$chosen, null];
        }

        $shown = mb_substr($file, 0, 100);

        return ['', new ImportMessage('post.template-default', "WordPress drew it with the template {$shown}, which your choices don’t match to one of the theme’s templates, so it uses the theme’s default.", ['template' => $shown])];
    }

    /**
     * The image fields left only where they hold an image, as the field
     * accepts; what to say of each that didn't.
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array{0: array<string, mixed>, 1: list<ImportMessage>}
     */
    private static function images(array $fields): array
    {
        $ids = [];

        foreach (self::IMAGES as [$key]) {
            if (is_array($fields[$key] ?? null) && is_string($fields[$key]['id'] ?? null)) {
                $ids[] = $fields[$key]['id'];
            }
        }

        $kinds = $ids === [] ? [] : DB::table('media')->whereIn('id', $ids)->pluck('kind', 'id')->all();
        $notes = [];

        foreach (self::IMAGES as [$key, $name]) {
            $id = is_array($fields[$key] ?? null) ? ($fields[$key]['id'] ?? null) : null;

            if (is_string($id) && ($kinds[$id] ?? null) !== 'image') {
                unset($fields[$key]);
                $notes[] = new ImportMessage('post.not-an-image', "Its {$name} on WordPress is a file Teme can’t show as an image, so it has none.", ['field' => $name]);
            }
        }

        return [$fields, $notes];
    }

    /**
     * What to say of its status: a private item's password left behind, the
     * trash, a schedule whose time has passed.
     *
     * @param  array{status: string, visibility: string|null, trashed: bool, dropped: bool}  $state
     *
     * @return list<ImportMessage>
     */
    private static function stateNotes(array $state, ?CarbonImmutable $published, CarbonImmutable $now): array
    {
        $notes = [];

        if ($state['dropped']) {
            $notes[] = new ImportMessage('post.password-dropped', 'It was private on WordPress and had a password as well. Teme keeps it private, readable only by people who can edit it, so the password wasn’t kept.');
        }

        if ($state['trashed']) {
            $notes[] = new ImportMessage('post.trashed', 'It was in WordPress’s trash, so it’s in Teme’s trash too, counted from the day it was trashed there.');
        }

        if ($state['status'] === 'scheduled' && $published !== null && ! $published->greaterThan($now)) {
            $when = $published->utc()->format('Y-m-d H:i').' UTC';
            $notes[] = new ImportMessage('post.schedule-passed', "It was scheduled on WordPress for {$when}, which has passed, so Teme publishes it within a minute, as WordPress meant to.", ['when' => $when]);
        }

        return $notes;
    }

    /**
     * Whether the page-parents step will put it under its WordPress parent,
     * at its WordPress address, so its flat address now is no move to warn
     * of (I-3363).
     *
     * @param  array<string, mixed>  $item
     */
    private function placedLater(ContentType $type, array $item): bool
    {
        $settings = AddressSettings::current();

        return $type->hierarchical
            && self::number($item['parent'] ?? null) > 0
            && $settings->hasPattern($type->key)
            && in_array(AddressPattern::PARENT, $settings->pattern($type->key)->tokens(), true);
    }

    /**
     * What to say of its addresses: WordPress's own kept answering, or
     * answering another target (an error, so the check can't pass a wrong
     * page), old ones another target holds, more than an item keeps. A
     * message holds 1,000 characters, so an address is cut to 300.
     *
     * @param  array{moved: array{from: string, to: string}|null, taken: int, many: bool, clash: array{from: string, holder: string}|null, kept?: list<array{title: string, path: string, address: string, holder: string}>}  $written
     *
     * @return list<ImportMessage>
     */
    private static function addressNotes(array $written): array
    {
        $notes = [];

        if ($written['moved'] !== null) {
            $from = mb_substr($written['moved']['from'], 0, 300);
            $to = mb_substr($written['moved']['to'], 0, 300);
            $notes[] = new ImportMessage('post.address-moved', "WordPress had it at /{$from}/, and Teme gives it /{$to}/, so the old address sends readers on to the new one.", ['from' => $from, 'to' => $to]);
        }

        if ($written['clash'] !== null) {
            $from = mb_substr($written['clash']['from'], 0, 300);
            $holder = mb_substr($written['clash']['holder'], 0, 300);
            $notes[] = ImportMessage::error('post.address-taken', "WordPress had it at /{$from}/, which leads to {$holder} here, so links to that old address reach the wrong page. Change one of the two addresses, then run the import again.", ['from' => $from]);
        }

        if ($written['taken'] > 0) {
            $count = $written['taken'];
            $notes[] = $count === 1
                ? new ImportMessage('post.old-addresses-taken', 'One of its old addresses already leads somewhere else here, so it doesn’t lead to this item.', ['count' => 1])
                : new ImportMessage('post.old-addresses-taken', "{$count} of its old addresses already lead somewhere else here, so they don’t lead to this item.", ['count' => $count]);
        }

        if ($written['many']) {
            $notes[] = new ImportMessage('post.old-addresses-many', 'It had more old addresses on WordPress than Teme keeps for one item, so only the first 50 were kept.');
        }

        // A page under it that couldn't move, because its new address is someone else's (I-3363).
        foreach ($written['kept'] ?? [] as $kept) {
            $notes[] = new ImportMessage('page.kept-address', app(SubtreeWords::class)->kept($kept['title'], $kept['path'], $kept['address'], $kept['holder']), ['title' => mb_substr($kept['title'], 0, 300)]);
        }

        return $notes;
    }

    private static function changedHere(): ImportMessage
    {
        return new ImportMessage('post.changed-here', 'It was changed in Teme since the last import, so Teme kept its version rather than WordPress’s.');
    }

    /**
     * Whether the converter left out media Teme hasn't got yet.
     */
    private static function lacksMedia(Conversion $conversion): bool
    {
        foreach ($conversion->messages as $message) {
            if (in_array($message->code, self::MISSING, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What each active shortcode drew on the old site, once the companion
     * sends it (I-1704).
     *
     * @param  array<string, mixed>  $item
     *
     * @return list<array{open: string, html: string}>
     */
    private static function shortcodes(array $item): array
    {
        $kept = [];

        foreach (is_array($item['shortcodes'] ?? null) ? $item['shortcodes'] : [] as $one) {
            if (is_array($one) && is_string($one['open'] ?? null) && is_string($one['html'] ?? null)) {
                $kept[] = ['open' => $one['open'], 'html' => $one['html']];
            }
        }

        return $kept;
    }

    /**
     * A whole number the companion sent as a number or digits, else 0.
     */
    private static function number(mixed $value): int
    {
        return is_int($value) ? $value : (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 ? (int) $value : 0);
    }
}
