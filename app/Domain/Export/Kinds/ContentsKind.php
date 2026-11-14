<?php

declare(strict_types=1);

namespace App\Domain\Export\Kinds;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\PublicRequestPath;
use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportJson;
use App\Domain\Export\ExportKind;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Every item not in the trash, of every type, with the revision readers see
 * and the draft being edited, each as stored: Tiptap JSON (rule 7) and every
 * reference an ID (rule 9). Read 100 items at a time by ID.
 */
final readonly class ContentsKind implements ExportKind
{
    /** Items a page: documents are the largest rows the export reads. */
    private const int PAGE = 100;

    private const string PAGE_SQL = <<<'SQL'
        SELECT c.id, c.type, c.slug, c.status, c.author_id, c.published_at, c.scheduled_for, c.comment_status, c.visibility, c.parent_id, c.position, c.language, c.translation_group, c.created_at, c.updated_at,
               p.id AS p_id, p.title AS p_title, p.excerpt AS p_excerpt, p.document AS p_document, p.fields AS p_fields,
               p.author_id AS p_author_id, p.origin AS p_origin, p.created_at AS p_created_at,
               w.id AS w_id, w.title AS w_title, w.excerpt AS w_excerpt, w.document AS w_document, w.fields AS w_fields,
               w.author_id AS w_author_id, w.origin AS w_origin, w.created_at AS w_created_at
        FROM contents c
        LEFT JOIN content_revisions p ON p.id = c.published_revision_id
        LEFT JOIN content_revisions w ON w.id = c.working_revision_id AND c.working_revision_id IS DISTINCT FROM c.published_revision_id
        WHERE c.deleted_at IS NULL AND c.id > ?
        ORDER BY c.id
        LIMIT ?
        SQL;

    /**
     * The address settings, resolved with the kind, before the export's
     * transaction: reading them fills the settings cache, which must never
     * happen inside the export's snapshot (I-365). Addresses::toUrl() reads
     * this same scoped instance later.
     */
    public function __construct(private AddressSettings $addresses) {}

    public function name(): string
    {
        return 'contents';
    }

    public function version(): int
    {
        return 2;
    }

    public function description(): string
    {
        return 'Every item not in the trash, of every type (posts, pages, regions and any registered type), by ID.';
    }

    public function noun(): string
    {
        return 'item|items';
    }

    public function fields(): array
    {
        return [
            'id' => 'The item\'s ID.',
            'type' => 'Its content type\'s key, such as `post` or `page`.',
            'slug' => 'The last part of its address.',
            'status' => '`draft`, `review`, `scheduled` or `published`.',
            'author_id' => 'The account it belongs to, or null.',
            'bylines' => 'The IDs of the people it credits, from `bylines`, in order. Empty when it credits its owner, `author_id`.',
            'published_at' => 'When it was first published, or null.',
            'scheduled_for' => 'When it is due to be published, or null.',
            'comment_status' => 'Whether readers may comment: `open`, `closed` or `hidden`.',
            'visibility' => 'Who may read it: `public`, `private` (only people signed in who may see it) or `protected` (readers who know its password; the export never carries the password).',
            'parent_id' => 'The item it sits under, of the same type, or null. A child may come before its parent.',
            'position' => 'Its place among its parent\'s children, lowest first, then by ID.',
            'language' => 'The language it is written in, as a BCP 47 tag; null for the site\'s default language.',
            'translation_group' => 'The same for every language version of one item (or term); null for one with no other version.',
            'created_at' => 'When it was made.',
            'updated_at' => 'When it last changed.',
            'published' => 'The revision readers see, or null if it has never been published.',
            'published.id' => 'That revision\'s ID.',
            'published.title' => 'Its title.',
            'published.excerpt' => 'Its excerpt, or null.',
            'published.document' => 'Its body, as Tiptap JSON.',
            'published.fields' => 'Its fields, as an object.',
            'published.terms' => 'The IDs of its terms, in the order they were chosen.',
            'published.author_id' => 'Who wrote this revision, or null.',
            'published.origin' => '`human`, `ai` or `import`.',
            'published.created_at' => 'When this revision was saved.',
            'working' => 'The draft being edited, when it differs from the published revision; otherwise null.',
            'working.id' => 'That revision\'s ID.',
            'working.title' => 'Its title.',
            'working.excerpt' => 'Its excerpt, or null.',
            'working.document' => 'Its body, as Tiptap JSON.',
            'working.fields' => 'Its fields, as an object.',
            'working.terms' => 'The IDs of its terms, in the order they were chosen.',
            'working.author_id' => 'Who wrote this revision, or null.',
            'working.origin' => '`human`, `ai` or `import`.',
            'working.created_at' => 'When this revision was saved.',
            'computed' => 'Values worked out when the export was made, never references.',
            'computed.address' => 'Its address on the site, relative to the site\'s own (such as `/2026/10/harbour-news/`), while readers can see it; otherwise null.',
        ];
    }

    public function lines(ExportContext $context): iterable
    {
        $page = $context->pageSize(self::PAGE);
        $after = '';

        do {
            $rows = DB::select(self::PAGE_SQL, [$after, $page]);

            if ($rows === []) {
                return;
            }

            $ids = array_map(fn (object $row): string => (string) $row->id, $rows);
            $terms = $this->terms($ids);
            $bylines = $this->bylines($ids);
            $addresses = $this->addresses($ids);

            foreach ($rows as $row) {
                $id = (string) $row->id;

                yield [
                    'id' => $id,
                    'type' => (string) $row->type,
                    'slug' => (string) $row->slug,
                    'status' => (string) $row->status,
                    'author_id' => $row->author_id === null ? null : (int) $row->author_id,
                    'bylines' => $bylines[$id] ?? [],
                    'published_at' => ExportJson::time($row->published_at),
                    'scheduled_for' => ExportJson::time($row->scheduled_for),
                    'comment_status' => (string) $row->comment_status,
                    'visibility' => (string) $row->visibility,
                    'parent_id' => $row->parent_id === null ? null : (string) $row->parent_id,
                    'position' => (int) $row->position,
                    'language' => $row->language === null ? null : (string) $row->language,
                    'translation_group' => $row->translation_group === null ? null : (string) $row->translation_group,
                    'created_at' => ExportJson::time($row->created_at),
                    'updated_at' => ExportJson::time($row->updated_at),
                    'published' => self::revision($row, 'p_', $terms[$id]['published'] ?? []),
                    'working' => self::revision($row, 'w_', $terms[$id]['working'] ?? []),
                    'computed' => ['address' => $addresses[$id] ?? null],
                ];
            }

            $after = (string) $rows[array_key_last($rows)]->id;
        } while (count($rows) === $page);
    }

    /**
     * One revision from the page's row, or null when the row has none
     * under that prefix.
     *
     * @param  list<string>  $terms
     *
     * @return array<string, mixed>|null
     */
    private static function revision(object $row, string $prefix, array $terms): ?array
    {
        $id = $row->{$prefix.'id'};

        if ($id === null) {
            return null;
        }

        $author = $row->{$prefix.'author_id'};

        return [
            'id' => (string) $id,
            'title' => (string) $row->{$prefix.'title'},
            'excerpt' => $row->{$prefix.'excerpt'} === null ? null : (string) $row->{$prefix.'excerpt'},
            'document' => ExportJson::decode((string) $row->{$prefix.'document'}),
            'fields' => ExportJson::decode((string) $row->{$prefix.'fields'}),
            'terms' => $terms,
            'author_id' => $author === null ? null : (int) $author,
            'origin' => (string) $row->{$prefix.'origin'},
            'created_at' => ExportJson::time($row->{$prefix.'created_at'}),
        ];
    }

    /**
     * The page's terms, by item and scope, in the order they were chosen:
     * content_terms, whose keys a deleted term can't outlive, never a
     * revision's own list.
     *
     * @param  list<string>  $ids
     *
     * @return array<array-key, array<string, list<string>>>
     */
    private function terms(array $ids): array
    {
        $terms = [];

        $rows = DB::table('content_terms')->whereIn('content_id', $ids)
            ->orderBy('content_id')->orderBy('scope')->orderBy('position')
            ->get(['content_id', 'scope', 'term_id']);

        foreach ($rows as $row) {
            $terms[(string) $row->content_id][(string) $row->scope][] = (string) $row->term_id;
        }

        return $terms;
    }

    /**
     * The people each of the page's items credits, by ID, in order, in one
     * statement (I-3366).
     *
     * @param  list<string>  $ids
     *
     * @return array<string, list<string>>
     */
    private function bylines(array $ids): array
    {
        $bylines = [];

        foreach (DB::table('content_bylines')->whereIn('content_id', $ids)->orderBy('content_id')->orderBy('position')->get(['content_id', 'person_id']) as $row) {
            $bylines[(string) $row->content_id][] = (string) $row->person_id;
        }

        return $bylines;
    }

    /**
     * The page's items' addresses now, for those readers can see, in one
     * statement, as Tools › Redirects' export works them out
     * (app/Domain/Redirects/Queries/RedirectExport.php).
     *
     * @param  list<string>  $ids
     *
     * @return array<array-key, string>
     */
    private function addresses(array $ids): array
    {
        $settings = $this->addresses;
        $addresses = [];

        $rows = DB::table('contents as c')
            // c.id is char(26) and p.target_id varchar: cast c.id, so the paths index serves the join.
            ->join('paths as p', fn (JoinClause $join): JoinClause => $join->on('p.target_id', '=', DB::raw('c.id::text'))->where('p.target_type', 'content')->where('p.is_current', true))
            ->whereIn('c.id', $ids)->where('c.status', 'published')->where('c.visibility', '<>', 'private')->whereNull('c.deleted_at')
            ->whereNotNull('c.published_revision_id')->where('c.published_at', '<=', now())
            ->get(['c.id', 'c.type', 'p.path']);

        foreach ($rows as $row) {
            $addresses[(string) $row->id] = $settings->frontShows === AddressSettings::FRONT_PAGE && $settings->frontPageId === (string) $row->id
                ? '/'
                : PublicRequestPath::encode(Addresses::toUrl((string) $row->path, (string) $row->type));
        }

        return $addresses;
    }
}
