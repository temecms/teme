<?php

declare(strict_types=1);

namespace App\Domain\Benchmark;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Comments\DiscussionSettings;
use App\Domain\Contents\Operations\Input\RebuildCountsInput;
use App\Domain\Contents\Operations\RebuildCounts;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Redirects\RedirectProposal;
use App\Domain\Site\SiteLanguage;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Fills the benchmark database with study I's data set using PostgreSQL's own
 * generators, so a million items take minutes, not hours. Tooling, like a
 * database seeder: it writes directly and is never pointed at a site.
 */
final class BenchmarkSeeder
{
    private const array WORDS = [
        'garden', 'river', 'market', 'election', 'harvest', 'city', 'school', 'budget', 'storm', 'festival',
        'coast', 'bridge', 'library', 'forest', 'railway', 'museum', 'council', 'village', 'harbour', 'stadium',
        'orchard', 'factory', 'island', 'valley', 'desert', 'canal', 'tower', 'meadow', 'clinic', 'theatre',
        'kitchen', 'studio', 'airport', 'reserve', 'quarry', 'lighthouse', 'vineyard', 'glacier', 'lagoon', 'plateau',
        'summit', 'estuary', 'prairie', 'workshop', 'gallery', 'arena', 'station', 'terrace', 'courtyard', 'pavilion',
    ];

    /**
     * One item in 500 is written by editor@bench.test rather than one of the
     * 200 authors, so the dashboard's panels about their own work are full.
     */
    private const int EDITOR_EVERY = 500;

    private const int EDITOR_ITEM = 272;

    /**
     * The published post with twenty tags, where others have two or three,
     * for the public article scenario's fixed query count (1.6h).
     */
    public const int TWENTY_TAGS_ITEM = 9;

    /**
     * Words planted at the end of the third paragraph, for the site search
     * scenarios (1.6h): neither is in WORDS. `kittiwake` is in every item
     * n % 10,000 = 7, 100 published posts at a million; `tidewater` in every
     * item n % 10 = 3, 100,000 items.
     */
    public const string RARE_WORD = 'kittiwake';

    public const string COMMON_WORD = 'tidewater';

    /** The post with the long thread: every eighth comment seeded. */
    public const int THREAD_ITEM = 17;

    /**
     * Comments go on the published posts numbered n % 100 = COMMENTED below
     * 100,000, about a thousand at a million items, THREAD_ITEM among them.
     */
    public const int COMMENTED = 17;

    /** A published post with no comment seeded on it, for the spam Empty spam deletes. */
    public const int EMPTYING_ITEM = 27;

    /** The browser every seeded comment that keeps one came from. */
    public const string COMMENTER_BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) Bench/1.0';

    /**
     * The published post whose head takes every fallback: no featured image
     * (n % 10 >= 6), no image in its body, category 248, three levels deep,
     * and on no other scenario's list (docs/studies/seo.md §3.15).
     */
    public const int FALLBACK_ITEM = 247;

    /** A page in the trash, with no address, which FALLBACK_ITEM's canonical names. */
    public const int TRASHED_PAGE = 50;

    /** How many of the seeded redirects are patterns, at most a tenth of them; the rest are exact. */
    public const int PATTERNS = 100;

    /** AI requests written by one statement: a million, so no one statement holds the whole record. */
    private const int AI_CHUNK = 1_000_000;

    /** Spreads the featured images over the media: set from the media count. */
    private int $spread = 1;

    /**
     * Seeds $items content items, $media media items (as many as items by
     * default), and everything around them: revision histories with deep
     * cases, autosaves, scheduled publications, reviews and search text, and,
     * when asked, the comments docs/studies/comments.md §3.11 sets, and the
     * redirects and the 404 log docs/studies/seo.md §3.15 sets.
     */
    public function seed(int $items, ?int $media = null, int $comments = 0, int $redirects = 0, int $notFound = 0, int $aiActivity = 0): void
    {
        $media ??= $items;

        (new RoleSeeder)->run();

        $this->spread = max(1, intdiv($media, 100) - 1);

        $this->users();
        $this->terms();
        $this->contents($items);
        $this->paths();
        $this->revisions();
        $this->history($items);
        // Counted before any statement joins them, or the planner takes a
        // table it last saw empty for one row and nests loops over millions
        // (B-136); an UPDATE planned so ran for hours (B-198).
        DB::statement('ANALYZE contents, content_revisions');
        $this->pointers();
        $this->projection();
        $this->media($media, $items);
        DB::statement('ANALYZE media');
        $this->featuredImages();
        DB::statement('ANALYZE contents, content_revisions');
        $this->longArticle();
        $this->autosaves();
        $this->schedules();
        $this->reviews();
        $this->searchText($items);
        $this->comments($comments);
        $this->fallbackArticle();
        $this->redirects($redirects, $items, $media);
        $this->notFoundLog($notFound);
        $this->aiActivity($aiActivity, $items, $media);

        // The planner must count the rows just inserted before the recount
        // runs. Otherwise a table an earlier analyze saw empty is planned as
        // one row, and the recount nests loops over every term (B-136).
        DB::statement('ANALYZE');

        app()->instance(Elevation::class, new OperatorElevation);
        app(RebuildCounts::class)->execute(new RebuildCountsInput(fix: true));

        DB::statement('ANALYZE');
    }

    /** The ID the seeder gives item $n. */
    public static function itemId(int $n): string
    {
        return 'C'.str_pad((string) $n, 25, '0', STR_PAD_LEFT);
    }

    /**
     * The IP address of the comment numbered by the SQL expression $number,
     * as SQL: the seeder writes it, and the erasure's scenario puts it back.
     */
    public static function commenterAddress(string $number): string
    {
        return "('198.51.100.' || (1 + ({$number}) % 250))::inet";
    }

    private function users(): void
    {
        $hash = Hash::make('bench-password-never-used');

        DB::statement(<<<'SQL'
            INSERT INTO users (name, slug, email, password, email_verified_at, created_at, updated_at)
            SELECT 'Author ' || g, 'author-' || g, 'author' || g || '@bench.test', ?, now(), now(), now() FROM generate_series(1, 200) g
            UNION ALL SELECT 'Bench Admin', 'bench-admin', 'admin@bench.test', ?, now(), now(), now()
            UNION ALL SELECT 'Bench Editor', 'bench-editor', 'editor@bench.test', ?, now(), now(), now()
            SQL, [$hash, $hash, $hash]);

        $roles = DB::table('roles')->pluck('id', 'name');

        // author3 is a contributor, for the All tab as someone who may edit
        // only their own unpublished work (list.contributor_all).
        DB::statement(<<<'SQL'
            INSERT INTO model_has_roles (role_id, model_type, model_id)
            SELECT CASE email
                    WHEN 'admin@bench.test' THEN ?::bigint
                    WHEN 'editor@bench.test' THEN ?::bigint
                    WHEN 'author3@bench.test' THEN ?::bigint
                    ELSE ?::bigint
                END, 'App\Models\User', id
            FROM users WHERE email LIKE '%@bench.test'
            SQL, [$roles['administrator'], $roles['editor'], $roles['contributor'], $roles['author']]);
    }

    private function terms(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO terms (id, taxonomy, name, slug, parent_id, fields, published_count, created_at, updated_at)
            SELECT 'T' || lpad(g::text, 25, '0'), 'category', 'Category ' || g, 'category-' || g,
                CASE
                    WHEN g <= 20 THEN NULL
                    WHEN g <= 220 THEN 'T' || lpad((1 + (g - 21) / 10)::text, 25, '0')
                    WHEN g <= 1220 THEN 'T' || lpad((21 + (g - 221) / 5)::text, 25, '0')
                    ELSE 'T' || lpad((221 + (g - 1221))::text, 25, '0')
                END,
                '{}', 0, now(), now()
            FROM generate_series(1, 2000) g
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO terms (id, taxonomy, name, slug, parent_id, fields, published_count, created_at, updated_at)
            SELECT 'T' || lpad(g::text, 25, '0'), 'tag', 'Tag ' || (g - 2000), 'tag-' || (g - 2000), NULL, '{}', 0, now(), now()
            FROM generate_series(2001, 52000) g
            SQL);
    }

    private function contents(int $items): void
    {
        $words = '{'.implode(',', self::WORDS).'}';
        $editor = (int) DB::table('users')->where('email', 'editor@bench.test')->value('id');

        // Reviews (reviews()): items n % 3000 = 71 and 272 wait in review;
        // 72 and 772 were returned; 272 and 772 are the editor's. A pending
        // schedule (schedules()) sits on each scheduled item n % 300 = 97,
        // due within 30 days, and scheduled_for equals its run_at (1.5e).
        //
        // author_sort and fields are written here, not by a later UPDATE,
        // which would leave a million dead rows (B-189) and crawled (B-198).
        // The author is the (1 + n % 200)th of the authors users() inserts in
        // order as Author 1 to Author 200, or the editor, so this is
        // AuthorSort::of() of their name; fields is the published revision's;
        // comment_status too, for the same reason.
        DB::statement(str_replace('%FEATURED_G%', $this->featured('g'), <<<'SQL'
            INSERT INTO contents (id, type, slug, status, author_id, author_sort, title, fields, published_at, scheduled_for, created_at, updated_at, deleted_at, comment_status)
            SELECT
                'C' || lpad(g::text, 25, '0'),
                CASE WHEN g % 10 = 0 THEN 'page' ELSE 'post' END,
                'item-' || g,
                CASE
                    WHEN g % 3000 IN (71, 272) THEN 'review'
                    WHEN g % 100 < 70 THEN 'published'
                    WHEN g % 100 < 97 THEN 'draft'
                    ELSE 'scheduled'
                END,
                CASE WHEN g % ?::int = ?::int THEN ?::bigint ELSE (SELECT min(id) FROM users WHERE email LIKE 'author%@bench.test') + (g % 200) END,
                CASE WHEN g % ?::int = ?::int THEN 'bench editor' ELSE 'author ' || (1 + g % 200) END,
                initcap(w[1 + g % 50] || ' ' || w[1 + (g / 50) % 50] || ' ' || w[1 + (g / 2500) % 50]) || ' ' || g,
                CASE WHEN g % 100 < 70 AND g % 3000 NOT IN (71, 272) THEN %FEATURED_G% ELSE '{}'::jsonb END,
                CASE WHEN g % 100 < 70 THEN now() - make_interval(mins => g) END,
                CASE
                    WHEN g % 100 >= 97 AND g % 300 = 97 THEN now() + make_interval(mins => (1 + (g::bigint * 7919) % 43200)::int)
                    WHEN g % 100 >= 97 THEN now() + make_interval(mins => g)
                END,
                now() - make_interval(mins => g),
                now() - make_interval(mins => (g * 7) % 525600),
                CASE WHEN g % 50 = 0 THEN now() - make_interval(days => g % 60) END,
                -- Each type's start: posts open, pages closed.
                CASE WHEN g % 10 = 0 THEN 'closed' ELSE 'open' END
            FROM generate_series(1, ?::int) g, (SELECT ?::text[] AS w) words
            SQL), [self::EDITOR_EVERY, self::EDITOR_ITEM, $editor, self::EDITOR_EVERY, self::EDITOR_ITEM, $items, $words]);
    }

    /**
     * Each published, live item's address, at the root as `/{slug}/` puts
     * it, and an old address beside every tenth (n % 10 = 1, posts), as
     * PathProjection would have left them; and every category's, tag's and
     * bench author's, as Addresses::forTerm() and forUser() give them with
     * the site's bases (1.6h), so the archives answer. No Operation runs
     * here, so nothing else writes them.
     */
    private function paths(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO paths (path, target_type, target_id, is_current, created_at, replaced_at)
            SELECT slug, 'content', id, true, published_at, NULL
            FROM contents WHERE status = 'published' AND deleted_at IS NULL
            UNION ALL
            SELECT 'old-' || slug, 'content', id, false, published_at - interval '1 day', published_at
            FROM contents WHERE status = 'published' AND deleted_at IS NULL AND substr(id, 2)::bigint % 10 = 1
            SQL);

        $settings = AddressSettings::current();
        $prefix = fn (string $base): string => $base === '' ? '' : $base.'/';

        // A category's address is its slugs from its root down, as
        // Addresses::termPath() walks them.
        DB::statement(<<<'SQL'
            WITH RECURSIVE chain AS (
                SELECT id, slug::text AS path FROM terms WHERE taxonomy = 'category' AND parent_id IS NULL
                UNION ALL
                SELECT t.id, chain.path || '/' || t.slug FROM terms t JOIN chain ON t.parent_id = chain.id
            )
            INSERT INTO paths (path, target_type, target_id, is_current, created_at, replaced_at)
            SELECT lower(?::text || chain.path), 'term', chain.id, true, now(), NULL FROM chain
            SQL, [$prefix($settings->base('category'))]);

        DB::statement(<<<'SQL'
            INSERT INTO paths (path, target_type, target_id, is_current, created_at, replaced_at)
            SELECT lower(?::text || slug), 'term', id, true, now(), NULL FROM terms WHERE taxonomy = 'tag'
            SQL, [$prefix($settings->base('tag'))]);

        DB::statement(<<<'SQL'
            INSERT INTO paths (path, target_type, target_id, is_current, created_at, replaced_at)
            SELECT lower(?::text || slug), 'user', id::text, true, now(), NULL FROM users WHERE email LIKE '%@bench.test'
            SQL, [$prefix($settings->authorBase)]);

        DB::statement('ANALYZE paths');
    }

    private function revisions(): void
    {
        // Three paragraphs of twelve words each, picked from WORDS by the row
        // number and the revision, so a body search matches some articles
        // and not others. Written by the INSERT itself: an UPDATE of three
        // million revisions would leave as many dead rows (B-189).
        DB::statement(<<<SQL
            INSERT INTO content_revisions (id, content_id, document, plain_text, word_count, fields, terms, title, author_id, origin, created_at)
            SELECT
                'R' || lpad((c.n * 3 - k)::text, 25, '0'),
                c.id,
                {$this->bodyDocument()},
                {$this->bodyText()},
                36,
                {$this->featured('c.n')},
                {$this->termsOf()},
                c.title,
                c.author_id,
                'human',
                c.created_at + make_interval(mins => 2 - k)
            FROM (SELECT id, title, author_id, created_at, substr(id, 2)::bigint AS n FROM contents WHERE id LIKE 'C%') c
            CROSS JOIN generate_series(0, 2) k
            CROSS JOIN LATERAL ({$this->paragraphs('c.n', 'k')}) body
            SQL, [$this->words()]);
    }

    /**
     * Older revisions on top of every item's three: 40 on each item
     * n % 100 = 51 (published posts), and 110 on the deep article, item 1,
     * so its history is five pages at RevisionHistory::PAGE. Their IDs sit
     * above 3 × items, clear of revisions()'s, and their times before the
     * item's own three, so the working revision stays the newest.
     */
    private function history(int $items): void
    {
        $deepItems = intdiv($items + 49, 100);

        DB::statement(<<<SQL
            INSERT INTO content_revisions (id, content_id, document, plain_text, word_count, fields, terms, title, author_id, origin, created_at)
            SELECT
                'R' || lpad((?::bigint * 3 + c.first + m)::text, 25, '0'),
                c.id,
                {$this->bodyDocument()},
                {$this->bodyText()},
                36,
                {$this->featured('c.n')},
                {$this->termsOf()},
                c.title,
                c.author_id,
                'human',
                c.created_at - make_interval(mins => m)
            FROM (
                SELECT id, title, author_id, created_at, n,
                    CASE WHEN n = 1 THEN 110 ELSE 40 END AS depth,
                    CASE WHEN n = 1 THEN 40 * ?::bigint ELSE 40 * ((n - 51) / 100) END AS first
                FROM (SELECT id, title, author_id, created_at, substr(id, 2)::bigint AS n FROM contents WHERE id LIKE 'C%') numbered
                WHERE n = 1 OR n % 100 = 51
            ) c
            CROSS JOIN LATERAL generate_series(1, c.depth) m
            CROSS JOIN LATERAL ({$this->paragraphs('c.n', '2 + m')}) body
            SQL, [$items, $deepItems, $this->words()]);
    }

    /**
     * The long article, item 2: its two newest revisions rewritten as about
     * 20,000 words that differ in a few hundred places, for editor.compare.
     * Two rows, rewritten in place.
     */
    private function longArticle(): void
    {
        DB::statement(<<<'SQL'
            UPDATE content_revisions r SET document = d.document, plain_text = d.text, word_count = 20000
            FROM (
                SELECT k,
                    jsonb_build_object('type', 'doc', 'content', jsonb_agg(
                        jsonb_build_object('type', 'paragraph', 'content', jsonb_build_array(jsonb_build_object('type', 'text', 'text', para)))
                        ORDER BY p)) AS document,
                    string_agg(para, ' ' ORDER BY p) AS text
                FROM (
                    SELECT k, p, string_agg(
                        CASE WHEN k = 0 AND (p * 50 + i) % 67 = 0 THEN w[1 + (p * 7 + i * 13 + 25) % 50] ELSE w[1 + (p * 7 + i * 13) % 50] END,
                        ' ' ORDER BY i) || '.' AS para
                    FROM generate_series(0, 1) k, generate_series(1, 400) p, generate_series(1, 50) i, (SELECT ?::text[] AS w) words
                    GROUP BY k, p
                ) paragraphs
                GROUP BY k
            ) d
            WHERE r.id = 'R' || lpad((6 - d.k)::text, 25, '0')
            SQL, [$this->words()]);
    }

    /**
     * An autosave on 1 in 1,000 articles (n % 1000 = 51, so each is one of
     * the 43-revision items), by its author; half of them stale, based on the
     * revision before the working one.
     */
    private function autosaves(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO content_autosaves (id, content_id, user_id, title, document, fields, terms, based_on_revision_id, word_count, saved_at)
            SELECT
                'A' || lpad(c.n::text, 25, '0'),
                c.id,
                c.author_id,
                c.title || ' (edited)',
                r.document,
                r.fields,
                r.terms,
                CASE WHEN c.n % 2000 = 51 THEN 'R' || lpad((c.n * 3 - 1)::text, 25, '0') ELSE c.working_revision_id END,
                r.word_count,
                now() - make_interval(mins => (c.n % 600)::int)
            FROM (SELECT id, title, author_id, working_revision_id, substr(id, 2)::bigint AS n FROM contents WHERE id LIKE 'C%') c
            JOIN content_revisions r ON r.id = c.working_revision_id
            WHERE c.n % 1000 = 51
            SQL);
    }

    /**
     * A pending scheduled publication on each scheduled item n % 300 = 97,
     * a few thousand across the next 30 days, run_at equal to the item's
     * scheduled_for (contents() set it), scheduled by the administrator.
     */
    private function schedules(): void
    {
        $admin = (int) DB::table('users')->where('email', 'admin@bench.test')->value('id');

        DB::statement(<<<'SQL'
            INSERT INTO scheduled_publications (id, content_id, revision_id, action, run_at, created_by, attempts, created_at, updated_at)
            SELECT 'S' || lpad(substr(id, 2), 25, '0'), id, working_revision_id, 'publish', scheduled_for, ?::bigint, 0, now(), now()
            FROM contents
            WHERE id LIKE 'C%' AND status = 'scheduled' AND deleted_at IS NULL AND substr(id, 2)::bigint % 300 = 97
            SQL, [$admin]);
    }

    /**
     * A few hundred reviews waiting (their items in review) and a few
     * hundred returned, each submitted by the item's author and returned by
     * the administrator; the editor's among them fill their own panels.
     */
    private function reviews(): void
    {
        $admin = (int) DB::table('users')->where('email', 'admin@bench.test')->value('id');

        DB::statement(<<<'SQL'
            INSERT INTO content_reviews (id, content_id, revision_id, submitted_by, submitted_at, decided_by, decided_at, decision, note)
            SELECT
                'V' || lpad(c.n::text, 25, '0'),
                c.id,
                c.working_revision_id,
                c.author_id,
                now() - make_interval(hours => (1 + c.n % 72)::int),
                CASE WHEN c.returned THEN ?::bigint END,
                CASE WHEN c.returned THEN now() - make_interval(mins => (c.n % 1440)::int) END,
                CASE WHEN c.returned THEN 'returned' END,
                CASE WHEN c.returned THEN 'Tighten the opening, and check the figures in the second paragraph.' END
            FROM (
                SELECT id, author_id, working_revision_id, substr(id, 2)::bigint AS n, substr(id, 2)::bigint % 3000 IN (72, 772) AS returned
                FROM contents
                WHERE id LIKE 'C%' AND deleted_at IS NULL AND substr(id, 2)::bigint % 3000 IN (71, 72, 272, 772)
            ) c
            SQL, [$admin]);
    }

    /**
     * search_text and search_tsv on every published article, from its
     * published revision, as SearchText keeps them: in batches of 50,000
     * rows by ID, so no one statement holds a million rows.
     */
    private function searchText(int $items): void
    {
        // Recorded beside search_tsv, or every row would read as waiting for a rebuild.
        $config = app(SiteLanguage::class)->searchConfig();

        for ($start = 1; $start <= $items; $start += 50_000) {
            DB::statement(<<<'SQL'
                UPDATE contents c SET
                    search_text = r.plain_text,
                    search_tsv = to_tsvector(?::regconfig, c.title || ' ' || coalesce(r.plain_text, '')),
                    search_config = ?
                FROM content_revisions r
                WHERE r.id = c.published_revision_id
                  AND c.status = 'published' AND c.deleted_at IS NULL
                  AND c.id BETWEEN 'C' || lpad(?::text, 25, '0') AND 'C' || lpad(?::text, 25, '0')
                SQL, [$config, $config, $start, min($items, $start + 49_999)]);
        }
    }

    /**
     * The comments (docs/studies/comments.md §3.11), in one INSERT, as
     * everything here is written: an UPDATE after it would leave as many
     * dead rows. Comment s, from 1 to $count, has the ID lpad(s, 26, '0'), in
     * the comments' alphabet and in the order they arrived, over the last
     * 720 days, and legacy_id s, as an import leaves it. Every eighth is on
     * THREAD_ITEM, in blocks of 41 conversations and a chain of nine replies
     * under the last, so 5,000 make 4,100 conversations ten levels deep; the
     * rest go round the other commented posts, 24 in 35 approved, 1 pending,
     * 8 spam and 2 in the trash. Only those within keep_ip_days keep an
     * address and a browser, since an import leaves older ones out.
     */
    private function comments(int $count): void
    {
        $thread = self::itemId(self::THREAD_ITEM);

        if ($count < 1 || ! DB::table('contents')->where('id', $thread)->exists()) {
            return;
        }

        DB::statement(sprintf(<<<'SQL'
            WITH targets AS (
                SELECT id, row_number() OVER (ORDER BY id) - 1 AS r
                FROM contents
                WHERE id LIKE 'C%%' AND type = 'post' AND status = 'published' AND deleted_at IS NULL
                  AND substr(id, 2)::bigint %% 100 = %4$d AND substr(id, 2)::bigint BETWEEN 100 + %4$d AND 99999
            ),
            total AS (SELECT count(*) AS n FROM targets),
            numbered AS (
                SELECT s,
                    s %% 8 = 0 AS threaded,
                    (s / 8 - 1) %% 50 AS p,
                    s - s / 8 - 1 AS o,
                    (s - s / 8 - 1) %% NULLIF(total.n, 0) AS r,
                    now() - make_interval(secs => (%1$d - s) * 62208000.0 / %1$d) AS arrived
                FROM generate_series(1, %1$d) s CROSS JOIN total
            ),
            placed AS (
                SELECT numbered.*,
                    CASE WHEN numbered.threaded THEN ?::text ELSE targets.id END AS content_id,
                    CASE WHEN numbered.threaded AND numbered.p > 40 THEN numbered.p - 40 ELSE 0 END AS above,
                    CASE
                        WHEN numbered.threaded OR numbered.o %% 35 < 24 THEN 'approved'
                        WHEN numbered.o %% 35 = 24 THEN 'pending'
                        WHEN numbered.o %% 35 < 33 THEN 'spam'
                        ELSE 'trash'
                    END AS status
                FROM numbered
                LEFT JOIN targets ON NOT numbered.threaded AND targets.r = numbered.r
                WHERE numbered.threaded OR targets.id IS NOT NULL
            )
            INSERT INTO comments (id, content_id, parent_id, path, depth, kind, status, previous_status, author_name, author_email,
                body, ip, user_agent, legacy_id, created_at, updated_at, status_changed_at)
            SELECT
                lpad(s::text, 26, '0'),
                content_id,
                CASE WHEN above > 0 THEN lpad((s - 8)::text, 26, '0') END,
                (SELECT string_agg(lpad((s - 8 * a)::text, 26, '0') || '/', '' ORDER BY a DESC) FROM generate_series(0, above) a),
                above + 1,
                'comment',
                status,
                CASE WHEN status = 'trash' THEN 'approved' END,
                'Reader ' || s,
                'reader' || (s %% 5000) || '@bench.test',
                jsonb_build_object('type', 'doc', 'content', jsonb_build_array(jsonb_build_object('type', 'paragraph', 'content', jsonb_build_array(
                    jsonb_build_object('type', 'text', 'text', 'A word on the ' || w[1 + s %% 50] || ' and the ' || w[1 + (s / 50) %% 50] || ', comment ' || s || '.'))))),
                CASE WHEN arrived > now() - make_interval(days => %2$d) THEN %3$s END,
                CASE WHEN arrived > now() - make_interval(days => %2$d) THEN ? END,
                s,
                arrived,
                arrived,
                CASE WHEN status = 'trash' THEN LEAST(arrived + interval '1 day', now()) ELSE arrived END
            FROM placed, (SELECT ?::text[] AS w) words
            SQL, $count, DiscussionSettings::current()->keepIpDays, self::commenterAddress('s'), self::COMMENTED), [$thread, self::COMMENTER_BROWSER, $this->words()]);
    }

    /**
     * Gives FALLBACK_ITEM an excerpt and a canonical naming TRASHED_PAGE and
     * nothing else of its own, on the item and on the revision it shows, so
     * every value of its head falls back: its title to its type's template,
     * its description to the excerpt, its image to the site's default, and
     * its canonical, naming a page with no address, to its own. One row each:
     * no table is rewritten.
     */
    private function fallbackArticle(): void
    {
        $id = self::itemId(self::FALLBACK_ITEM);

        if (! DB::table('contents')->where('id', $id)->exists()) {
            return;
        }

        $fields = json_encode([
            BuiltInFields::EXCERPT => 'A story told through every fallback the head has.',
            BuiltInFields::CANONICAL => ['item' => self::itemId(self::TRASHED_PAGE)],
        ], JSON_THROW_ON_ERROR);

        DB::update('UPDATE contents SET fields = ?::jsonb WHERE id = ?', [$fields, $id]);
        DB::update('UPDATE content_revisions SET fields = ?::jsonb WHERE id = (SELECT published_revision_id FROM contents WHERE id = ?)', [$fields, $id]);
    }

    /**
     * The redirects (docs/studies/seo.md §3.15): PATTERNS patterns, read as a
     * save reads them, and the rest exact, in one INSERT, from addresses
     * under archive/ that no item, archive or pattern has. Exact redirect g
     * sends readers, by g % 100: below 70 to an address on the site, below
     * 90 to item 100 * (g % (items / 100)) + 1, a live post, below 95 to a
     * media file, below 99 to another site; the rest answer 410. One in
     * twenty is a 302. Its ID is g, so the list's newest are the highest.
     */
    private function redirects(int $count, int $items, ?int $media): void
    {
        if ($count < 1) {
            return;
        }

        $patterns = min(self::PATTERNS, intdiv($count, 10));
        $rows = [];

        for ($k = 1; $k <= $patterns; $k++) {
            $rows[] = [
                'id' => str_pad((string) (900_000_000 + $k), 26, '0', STR_PAD_LEFT),
                ...RedirectProposal::read("/topic-{$k}/[slug]", 301, "/subject-{$k}/[slug]/", null, null, '')->columns(),
                'origin' => 'import',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('redirects')->insert($rows);

        DB::statement(<<<'SQL'
            INSERT INTO redirects (id, source, kind, pattern, status, target_kind, target, target_path, origin, enabled, note, hits, created_at, updated_at)
            SELECT
                lpad(g::text, 26, '0'),
                'archive/' || (2010 + g % 10) || '/' || lpad((1 + g % 12)::text, 2, '0') || '/story-' || g,
                'exact',
                NULL,
                CASE WHEN g % 100 >= 99 THEN 410 WHEN g % 20 = 0 THEN 302 ELSE 301 END,
                CASE WHEN g % 100 < 70 THEN 'address' WHEN g % 100 < 90 THEN 'content' WHEN g % 100 < 95 THEN 'media' WHEN g % 100 < 99 THEN 'outside' END,
                CASE
                    WHEN g % 100 < 70 THEN '/story-' || g || '/'
                    WHEN g % 100 < 90 THEN 'C' || lpad((100 * (g % ?::int) + 1)::text, 25, '0')
                    WHEN g % 100 < 95 THEN 'M' || lpad((1 + g % ?::int)::text, 25, '0')
                    WHEN g % 100 < 99 THEN 'https://example.com/story-' || g
                END,
                CASE WHEN g % 100 < 70 THEN 'story-' || g END,
                'import',
                true,
                '',
                0,
                now() - make_interval(secs => ?::int - g),
                now() - make_interval(secs => ?::int - g)
            FROM generate_series(1, ?::int) g
            SQL, [max(1, intdiv($items, 100)), max(1, $media ?? $items), $count, $count, $count - $patterns]);
    }

    /**
     * The 404 log at $count addresses, none of them answered by anything
     * seeded: each counted 1 to 500 times, last seen g seconds ago, a third
     * from example.com. Public, so the minute's run scenario can put the log
     * back as it found it.
     */
    public function notFoundLog(int $count): void
    {
        if ($count < 1) {
            return;
        }

        DB::statement(<<<'SQL'
            INSERT INTO not_found_log (path, count, first_seen_at, last_seen_at, referrer_host)
            SELECT
                'missing/' || lpad(g::text, 7, '0') || '/' || md5(g::text),
                1 + (g * 7919) % 500,
                now() - make_interval(days => 30) - make_interval(secs => g),
                now() - make_interval(secs => g),
                CASE WHEN g % 3 = 0 THEN 'example.com' END
            FROM generate_series(1, ?::int) g
            SQL, [$count]);
    }

    /**
     * The AI activity record (I-318) at a busy site's size: $count requests
     * spread evenly over the 395 days before the seed, so the twelve months
     * the record keeps are full and a thirteenth waits for the prune; three
     * tasks, every outcome, the bench's people, its items and its files.
     * Then each month's totals, as RecordAiActivity keeps them.
     */
    private function aiActivity(int $count, int $items, int $media): void
    {
        if ($count < 1) {
            return;
        }

        for ($from = 1; $from <= $count; $from += self::AI_CHUNK) {
            DB::statement(<<<'SQL'
                INSERT INTO ai_activity (id, user_id, task, variant, provider, model, prompt_key, prompt_version, prompt_hash,
                    subject_type, subject_id, outcome, error_type, input_tokens, output_tokens, cost_microunits, latency_ms, created_at)
                SELECT
                    lpad(g::text, 26, '0'),
                    p.ids[1 + g % array_length(p.ids, 1)],
                    t.task,
                    CASE WHEN t.task = 'rewrite'
                        THEN (ARRAY['improve', 'shorter', 'longer', 'simpler', 'fix-spelling-and-grammar', 'more-formal', 'more-casual'])[1 + (g / 20) % 7]
                    END,
                    'anthropic',
                    CASE WHEN t.task = 'alt-text' THEN 'bench-vision' ELSE 'bench-text' END,
                    t.task,
                    1,
                    md5(t.task) || md5(t.task || '1'),
                    CASE WHEN t.task = 'alt-text' THEN 'media' ELSE 'content' END,
                    CASE WHEN t.task = 'alt-text'
                        THEN 'M' || lpad((1 + g % ?::int)::text, 25, '0')
                        ELSE 'C' || lpad((1 + g % ?::int)::text, 25, '0')
                    END,
                    o.outcome,
                    CASE o.outcome WHEN 'failed' THEN 'timeout' WHEN 'refused' THEN 'refused' END,
                    CASE WHEN o.outcome = 'failed' THEN 0 ELSE 800 + g % 1200 END,
                    CASE WHEN o.outcome IN ('failed', 'refused') THEN 0 ELSE 40 + g % 300 END,
                    CASE
                        WHEN o.outcome = 'failed' THEN 0
                        WHEN o.outcome = 'refused' THEN 3 * (800 + g % 1200)
                        ELSE 3 * (800 + g % 1200) + 15 * (40 + g % 300)
                    END,
                    1500 + g % 4000,
                    now() - make_interval(secs => g * (395 * 86400.0 / ?::int))
                FROM generate_series(?::int, ?::int) g
                CROSS JOIN (SELECT array_agg(id ORDER BY id) AS ids FROM users WHERE email LIKE '%@bench.test') p
                CROSS JOIN LATERAL (
                    SELECT CASE WHEN g % 20 < 12 THEN 'rewrite' WHEN g % 20 < 17 THEN 'metadata' ELSE 'alt-text' END AS task
                ) t
                CROSS JOIN LATERAL (
                    SELECT CASE
                        WHEN (g / 3) % 20 < 11 THEN 'suggested'
                        WHEN (g / 3) % 20 < 16 THEN 'applied'
                        WHEN (g / 3) % 20 = 16 THEN 'discarded'
                        WHEN (g / 3) % 20 = 17 THEN 'stale'
                        WHEN (g / 3) % 20 = 18 THEN 'failed'
                        ELSE 'refused'
                    END AS outcome
                ) o
                SQL, [max(1, $media), max(1, $items), $count, $from, min($count, $from + self::AI_CHUNK - 1)]);
        }

        // As RecordAiActivity adds each request to its month: every request counted, its cost or none.
        DB::statement(<<<'SQL'
            INSERT INTO ai_monthly_spend (month, cost_microunits, requests, unpriced_requests)
            SELECT date_trunc('month', created_at AT TIME ZONE 'UTC')::date, sum(coalesce(cost_microunits, 0)), count(*), count(*) FILTER (WHERE cost_microunits IS NULL)
            FROM ai_activity
            GROUP BY 1
            ON CONFLICT (month) DO UPDATE SET
                cost_microunits = EXCLUDED.cost_microunits,
                requests = EXCLUDED.requests,
                unpriced_requests = EXCLUDED.unpriced_requests
            SQL);
    }

    /**
     * An item's fields: its featured image when $row % 10 < 6, else none.
     * Item 1's is media item 1, the most used; the rest spread over the
     * media. Only integers and the field's own key are inlined.
     */
    private function featured(string $row): string
    {
        $key = BuiltInFields::FEATURED_IMAGE;

        return <<<SQL
            CASE WHEN ({$row}) % 10 < 6 THEN jsonb_build_object('{$key}', jsonb_build_object(
                'id', 'M' || lpad((CASE WHEN ({$row}) % 100 = 1 THEN 1 ELSE ((({$row})::bigint * 7919) % {$this->spread}) * 100 + ({$row}) % 79 + 1 END)::text, 25, '0'),
                'alt', NULL,
                'decorative', false)) ELSE '{}'::jsonb END
            SQL;
    }

    /** WORDS as a PostgreSQL array literal. */
    private function words(): string
    {
        return '{'.implode(',', self::WORDS).'}';
    }

    /**
     * A LATERAL subquery giving three paragraphs, p1 to p3, of twelve words
     * from WORDS (the statement's one binding), picked by $row and $revision.
     * hashint8() mixes them, so each row has its own text and a word is in
     * about half the articles, not in all of one class and none of another.
     * The third ends with the planted words its item number calls for, in
     * every revision, so both INSERTs write them and nothing rewrites them.
     */
    private function paragraphs(string $row, string $revision): string
    {
        $planted = "CASE WHEN ({$row}) % 10000 = 7 THEN ' ".ucfirst(self::RARE_WORD).".' ELSE '' END || CASE WHEN ({$row}) % 10 = 3 THEN ' ".ucfirst(self::COMMON_WORD).".' ELSE '' END";
        $paragraph = fn (int $p): string => <<<SQL
            (SELECT upper(left(s, 1)) || substr(s, 2) || '.'{$this->plantedIn($p, $planted)} FROM (
                SELECT string_agg(w[1 + ((hashint8(({$row}) * 1000 + ({$revision}) * 100 + {$p} * 20 + i) % 50 + 50) % 50)::int], ' ' ORDER BY i) AS s
                FROM generate_series(1, 12) i
            ) words_{$p}) AS p{$p}
            SQL;

        return "SELECT {$paragraph(1)}, {$paragraph(2)}, {$paragraph(3)} FROM (SELECT ?::text[] AS w) vocabulary";
    }

    /**
     * The planted words' SQL for paragraph $p: the third paragraph ends with
     * them, the first two have none.
     */
    private function plantedIn(int $p, string $planted): string
    {
        return $p === 3 ? ' || '.$planted : '';
    }

    /** The Tiptap document of the three paragraphs. */
    private function bodyDocument(): string
    {
        $paragraph = fn (string $text): string => "jsonb_build_object('type', 'paragraph', 'content', jsonb_build_array(jsonb_build_object('type', 'text', 'text', {$text})))";

        return "jsonb_build_object('type', 'doc', 'content', jsonb_build_array({$paragraph('body.p1')}, {$paragraph('body.p2')}, {$paragraph('body.p3')}))";
    }

    /** The same three paragraphs as plain text, one space between, as PlainText writes it. */
    private function bodyText(): string
    {
        return "body.p1 || ' ' || body.p2 || ' ' || body.p3";
    }

    /**
     * Each revision's terms: a category and two or three tags, from n; the
     * category and twenty tags on TWENTY_TAGS_ITEM.
     */
    private function termsOf(): string
    {
        $twenty = self::TWENTY_TAGS_ITEM;

        return <<<SQL
            CASE WHEN c.n = {$twenty} THEN jsonb_build_array('T' || lpad((1 + c.n % 2000)::text, 25, '0'))
                || (SELECT jsonb_agg('T' || lpad((2001 + (c.n * 7 + j * 997) % 50000)::text, 25, '0') ORDER BY j) FROM generate_series(0, 19) j)
            ELSE jsonb_build_array(
                    'T' || lpad((1 + c.n % 2000)::text, 25, '0'),
                    'T' || lpad((2001 + (c.n * 7) % 50000)::text, 25, '0'),
                    'T' || lpad((2001 + (c.n * 13) % 50000)::text, 25, '0')
                ) || CASE WHEN c.n % 2 = 0 THEN jsonb_build_array('T' || lpad((2001 + (c.n * 31) % 50000)::text, 25, '0')) ELSE '[]'::jsonb END
            END
            SQL;
    }

    private function pointers(): void
    {
        // Working is the newest revision (k = 0); published items publish the
        // one before it (k = 1), so a third of them have a draft waiting.
        DB::statement(<<<'SQL'
            UPDATE contents SET
                working_revision_id = 'R' || lpad(((substr(id, 2)::bigint) * 3)::text, 25, '0'),
                published_revision_id = CASE WHEN status = 'published'
                    THEN 'R' || lpad(((substr(id, 2)::bigint) * 3 - CASE WHEN substr(id, 2)::bigint % 3 = 0 THEN 1 ELSE 0 END)::text, 25, '0')
                END
            WHERE id LIKE 'C%'
            SQL);
    }

    private function projection(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO content_terms (content_id, term_id, scope, position)
            SELECT c.id, t.term_id, 'working', t.position - 1
            FROM contents c
            JOIN content_revisions r ON r.id = c.working_revision_id
            CROSS JOIN LATERAL jsonb_array_elements_text(r.terms) WITH ORDINALITY AS t(term_id, position)
            WHERE c.deleted_at IS NULL
            -- The tag arithmetic can give one item the same tag twice (for
            -- example when 6n is a multiple of 50,000); keep the first.
            ON CONFLICT (content_id, scope, term_id) DO NOTHING
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO content_terms (content_id, term_id, scope, position)
            SELECT c.id, t.term_id, 'published', t.position - 1
            FROM contents c
            JOIN content_revisions r ON r.id = c.published_revision_id
            CROSS JOIN LATERAL jsonb_array_elements_text(r.terms) WITH ORDINALITY AS t(term_id, position)
            WHERE c.deleted_at IS NULL AND c.status = 'published'
            ON CONFLICT (content_id, scope, term_id) DO NOTHING
            SQL);

        // RebuildCounts sets terms.published_count from these rows.
    }

    /**
     * The media: every tenth file uploaded for the item of its own number, when the item exists (I-553).
     */
    private function media(int $count, int $items): void
    {
        $words = '{'.implode(',', self::WORDS).'}';

        DB::statement(<<<'SQL'
            INSERT INTO media (id, kind, mime_type, extension, original_filename, name, original_path, file_version,
                size_bytes, sha256, width, height, animated, title, alt, caption, credit, focal_x, focal_y,
                variants, variant_set, processing, uploaded_by, uploaded_for, created_at, updated_at, deleted_at)
            SELECT
                m.id, m.kind,
                CASE m.kind WHEN 'image' THEN 'image/jpeg' WHEN 'document' THEN 'application/pdf' WHEN 'audio' THEN 'audio/mpeg' ELSE 'video/mp4' END,
                m.ext,
                m.name || '.' || m.ext,
                m.name,
                m.id || '/v1/' || m.name || '.' || m.ext,
                1,
                50000 + (g * 37) % 5000000,
                md5(m.id) || md5(m.id || 'x'),
                CASE WHEN m.kind = 'image' THEN 1200 + (g % 3000) END,
                CASE WHEN m.kind = 'image' THEN 800 + (g % 2000) END,
                false,
                initcap(replace(m.name, '-', ' ')),
                CASE WHEN m.kind = 'image' AND g % 10 < 7 THEN 'A photograph of the ' || w[1 + g % 50] || ' ' || g END,
                NULL, NULL, 50, 50,
                CASE WHEN m.kind = 'image' THEN jsonb_build_object(
                    'w320', jsonb_build_object('path', m.id || '/v1/' || m.name || '-w320.webp', 'width', 320, 'height', 213, 'bytes', 18000),
                    'w640', jsonb_build_object('path', m.id || '/v1/' || m.name || '-w640.webp', 'width', 640, 'height', 427, 'bytes', 52000),
                    'w960', jsonb_build_object('path', m.id || '/v1/' || m.name || '-w960.webp', 'width', 960, 'height', 640, 'bytes', 98000),
                    'w1280', jsonb_build_object('path', m.id || '/v1/' || m.name || '-w1280.webp', 'width', 1280, 'height', 853, 'bytes', 150000),
                    'thumb', jsonb_build_object('path', m.id || '/v1/' || m.name || '-thumb.webp', 'width', 300, 'height', 300, 'bytes', 14000)
                ) ELSE '{}'::jsonb END,
                1,
                'ready',
                (SELECT min(id) FROM users WHERE email LIKE 'author%@bench.test') + (g % 200),
                CASE WHEN g % 10 = 0 AND g <= ?::int THEN 'C' || lpad(g::text, 25, '0') END,
                now() - make_interval(mins => g),
                now() - make_interval(mins => g),
                CASE WHEN g % 100 = 0 THEN now() - make_interval(days => g % 60) END
            FROM generate_series(1, ?::int) g
            CROSS JOIN (SELECT ?::text[] AS w) words
            CROSS JOIN LATERAL (SELECT
                'M' || lpad(g::text, 25, '0') AS id,
                CASE WHEN g % 100 < 80 THEN 'image' WHEN g % 100 < 90 THEN 'document' WHEN g % 100 < 96 THEN 'audio' ELSE 'video' END AS kind,
                CASE WHEN g % 100 < 80 THEN 'jpg' WHEN g % 100 < 90 THEN 'pdf' WHEN g % 100 < 96 THEN 'mp3' ELSE 'mp4' END AS ext,
                w[1 + g % 50] || '-' || w[1 + (g / 50) % 50] || '-' || g AS name
            ) m
            SQL, [$items, $count, $words]);
    }

    /**
     * The featured images' usages. The images themselves are in the fields
     * the INSERTs wrote (featured()), on every revision of an item, so the
     * working and published scopes both see them.
     */
    private function featuredImages(): void
    {
        $key = BuiltInFields::FEATURED_IMAGE;

        foreach (['working' => 'working_revision_id', 'published' => 'published_revision_id'] as $scope => $pointer) {
            $publishedOnly = $scope === 'published' ? "AND c.status = 'published'" : '';

            // jsonb_exists(), not the ? operator: PDO would read a bare ? as
            // a placeholder.
            DB::statement(<<<SQL
                INSERT INTO media_usages (content_id, media_id, scope, ref)
                SELECT c.id, r.fields -> ? ->> 'id', '{$scope}', 'field:' || ?
                FROM contents c
                JOIN content_revisions r ON r.id = c.{$pointer}
                WHERE c.deleted_at IS NULL
                  AND jsonb_exists(r.fields, ?)
                  {$publishedOnly}
                SQL, [$key, $key, $key]);
        }

        // RebuildCounts sets media.working_uses and published_uses from these rows.
    }
}
