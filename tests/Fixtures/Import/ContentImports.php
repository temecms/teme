<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Comments\Discussion;
use App\Domain\Contents\ImportedItem;
use App\Domain\Contents\Operations\ImportContent;
use App\Domain\Contents\Operations\Input\ImportContentInput;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\Content\ContentPhase;
use App\Domain\Media\Models\Media;
use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Posts and pages for the import's tests (I-332): an item as the content
 * phase decides one, written through ImportContent; from the content phase's
 * task on, WordPress's items as the companion sends them, and runs of the
 * phase alone.
 */
final class ContentImports
{
    /**
     * A published post as the phase decides one: WordPress's "Harbour news",
     * by $author, published at 22:30 UTC on 3 May 2019, at harbour-news,
     * unless $changes say otherwise.
     *
     * @param  array<string, mixed>  $changes  ImportedItem's parameters, by name
     */
    public static function item(User $author, array $changes = []): ImportedItem
    {
        return new ImportedItem(...[
            'type' => 'post',
            'title' => 'Harbour news',
            'slug' => 'harbour-news',
            'status' => 'published',
            'publishedAt' => CarbonImmutable::parse('2019-05-03 22:30:00', 'UTC'),
            'createdAt' => CarbonImmutable::parse('2019-05-03 22:30:00', 'UTC'),
            'modifiedAt' => CarbonImmutable::parse('2019-05-04 08:00:00', 'UTC'),
            'trashedAt' => null,
            'authorId' => (string) $author->id,
            'schedulerId' => null,
            'discussion' => Discussion::Open,
            'document' => self::document('Boats came in at dawn.'),
            'fields' => [],
            'terms' => [],
            'guid' => 'https://old.example/?p=12',
            'wordpressPath' => 'harbour-news',
            'oldSlugs' => [],
            'oldDates' => [],
            ...$changes,
        ]);
    }

    /**
     * A document of one paragraph, as the converter makes one.
     *
     * @return array<string, mixed>
     */
    public static function document(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    /**
     * ImportContent for $item as $actor, or as the operator (at the server's
     * shell unless $shell is false), under a new ID unless $id names one.
     *
     * @return array{contentId: string, written: string, moved: array{from: string, to: string}|null, taken: int, many: bool, clash: array{from: string, holder: string}|null, links: list<array{from: string, to: string|null}>}
     */
    public static function import(ImportedItem $item, ?User $actor = null, bool $shell = true, ?string $id = null): array
    {
        $input = new ImportContentInput($id ?? strtolower((string) Str::ulid()), $item, $actor === null ? null : (string) $actor->id);
        $run = fn (): array => app(ImportContent::class)->execute($input);

        return $actor === null && $shell ? scElevated($run) : $run();
    }

    /**
     * One post as the companion's content endpoint sends it: WordPress's
     * post $id, "Harbour news", published by WordPress's user 3 at 22:30
     * UTC on 3 May 2019, at /harbour-news/, one paragraph, unless $fields
     * say otherwise.
     *
     * @param  array<string, mixed>  $fields
     *
     * @return array<string, mixed>
     */
    public static function wpItem(int $id, array $fields = []): array
    {
        return [
            'id' => $id,
            'type' => 'post',
            'status' => 'publish',
            'slug' => 'harbour-news',
            'title' => 'Harbour news',
            'date' => '2019-05-03 22:30:00',
            'date_gmt' => '2019-05-03 22:30:00',
            'modified_gmt' => '2019-05-04 08:00:00',
            'author' => 3,
            'parent' => 0,
            'menu_order' => 0,
            'comment_status' => 'open',
            'ping_status' => 'open',
            'password' => '',
            'sticky' => false,
            'format' => null,
            'template' => null,
            'featured_media' => null,
            'terms' => [],
            'excerpt' => '',
            'content' => '<!-- wp:paragraph --><p>Boats came in at dawn.</p><!-- /wp:paragraph -->',
            'has_blocks' => true,
            'blocks' => [wpBlock('core/paragraph', '<p>Boats came in at dawn.</p>')],
            'permalink' => 'https://old.example/harbour-news/',
            'old_slugs' => [],
            'old_dates' => [],
            'seo' => null,
            'comment_count' => 0,
            ...$fields,
        ];
    }

    /**
     * The map row the users phase leaves for WordPress's user $wordpressId, who became $person.
     */
    public static function person(string $sourceId, User $person, int $wordpressId): void
    {
        ImportRecords::entry($sourceId, ImportRecords::run($sourceId), 'user', (string) $wordpressId, 'created', [
            'teme_type' => 'user', 'teme_id' => (string) $person->id, 'facts' => json_encode(['nicename' => 'person-'.$wordpressId]),
        ]);
    }

    /**
     * The map row the terms phase leaves for WordPress's term $termTaxonomyId (term ID $termId), which became $term.
     */
    public static function term(string $sourceId, Term $term, int $termTaxonomyId, int $termId, string $wordpressTaxonomy): void
    {
        ImportRecords::entry($sourceId, ImportRecords::run($sourceId), 'term', (string) $termTaxonomyId, 'created', [
            'teme_type' => 'term', 'teme_id' => $term->id,
            'facts' => json_encode(['term_id' => $termId, 'taxonomy' => $wordpressTaxonomy, 'parent' => 0, 'path' => $wordpressTaxonomy.'/'.$term->slug]),
        ]);
    }

    /**
     * The map row the media phase leaves for WordPress's attachment $wordpressId, which became $media.
     */
    public static function attachment(string $sourceId, Media $media, int $wordpressId, int $parent = 0): void
    {
        ImportRecords::entry($sourceId, ImportRecords::run($sourceId), 'attachment', (string) $wordpressId, 'created', [
            'teme_type' => 'media', 'teme_id' => $media->id, 'facts' => json_encode(['parent' => $parent, 'page_path' => null, 'source' => null]),
        ]);
    }

    /**
     * The content phase alone, run to its end over these items as the
     * operator (or $actor), a full import with these choices unless told
     * otherwise.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $choices
     */
    public static function run(FakeCompanion $companion, ImportSource $source, array $items, ImportRunKind $kind = ImportRunKind::Full, array $choices = [], ?User $actor = null): ImportRun
    {
        $companion->items('content', $items);
        importPhaseAlone(app(ContentPhase::class));

        return importRun($source, $actor, $kind, $choices);
    }

    /**
     * The map row of WordPress's post or page $wordpressId, or null.
     */
    public static function row(string $sourceId, int $wordpressId): ?ImportMapEntry
    {
        return ImportMapEntry::query()->where('source_id', $sourceId)->where('object_type', 'post')->where('object_id', (string) $wordpressId)->first();
    }

    /**
     * The codes of a map row's messages, in the order they were written.
     *
     * @return list<string>
     */
    public static function codes(?ImportMapEntry $row): array
    {
        return $row === null ? [] : ImportMessageRecord::query()->where('map_id', $row->id)->orderBy('created_at')->orderBy('id')->pluck('code')->map(fn (mixed $code): string => (string) $code)->values()->all();
    }
}
