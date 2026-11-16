<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Operations\Input\RecordImportMessagesInput;
use App\Domain\Import\Operations\RecordImportMessages;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Source\SourcePage;
use App\Domain\Import\Source\SourceReader;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Illuminate\Support\Str;
use LogicException;

/**
 * The old site as a SourceReader, answered from memory as the companion's
 * export API shapes its answers: urls by kind, terms, settings and each
 * store's redirects; every request kept, so a test can see what a step
 * read. No HTTP, no network.
 */
final class OldSite implements SourceReader
{
    public const string HOME = 'https://old.example';

    /** The options of a site at /%postname%/, as the companion's settings fixture gives them. */
    private const array OPTIONS = [
        'blogname' => 'Old Example', 'blogdescription' => 'Notes from the harbour', 'timezone_string' => 'Europe/London',
        'gmt_offset' => '0', 'date_format' => 'j F Y', 'time_format' => 'H:i', 'start_of_week' => '1',
        'posts_per_page' => '10', 'posts_per_rss' => '10', 'rss_use_excerpt' => '0', 'show_on_front' => 'posts',
        'page_on_front' => '0', 'page_for_posts' => '0', 'permalink_structure' => '/%postname%/', 'category_base' => '',
        'tag_base' => '', 'default_category' => '1', 'default_post_format' => '0', 'site_icon' => '0', 'blog_public' => '1',
        'default_comment_status' => 'open', 'default_ping_status' => 'open', 'comment_registration' => '0',
        'require_name_email' => '1', 'close_comments_for_old_posts' => '0', 'close_comments_days_old' => '14',
        'thread_comments' => '1', 'thread_comments_depth' => '5', 'page_comments' => '0', 'comments_per_page' => '50',
        'comment_order' => 'asc', 'comment_moderation' => '0', 'comment_previously_approved' => '1',
        'comment_max_links' => '2', 'show_avatars' => '1', 'comments_notify' => '1', 'moderation_notify' => '1',
    ];

    /** @var list<array{list: string, query: array<string, scalar>, after: int|null, limit: int|null}> */
    public array $requests = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $urls = [];

    /** @var array<string, mixed> */
    private array $options = [];

    private string $locale = 'en_GB';

    private ?string $seo = null;

    /** @var list<array<string, mixed>> */
    private array $terms = [];

    /** @var array<string, mixed> */
    private array $redirects = [];

    /**
     * A connected site and a run of it, running in $phase, as ImportRecords
     * seeds them: the source's ID, the run's and the attempt's token.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function running(string $phase): array
    {
        $token = strtolower((string) Str::ulid());
        $source = ImportRecords::source();
        $run = ImportRecords::run($source, ['state' => 'running', 'phase' => $phase, 'phase_token' => $token, 'ended_at' => null]);

        return [$source, $run, $token];
    }

    /**
     * What a step of the run is given, as $actor, or as the operator (call
     * that inside scElevated()).
     */
    public static function context(string $sourceId, string $runId, ?User $actor = null, ImportRunKind $kind = ImportRunKind::Full): ImportContext
    {
        return new ImportContext($runId, $sourceId, $kind, $actor === null ? JobActor::operator() : JobActor::person($actor->id), null, 0, []);
    }

    /**
     * Runs a step from its start to its end, a job at a time, as its jobs
     * would, and returns every message it gave; given the attempt's token,
     * each job's messages are recorded as the run's own, as RunImportStep
     * records them.
     *
     * @return list<ImportMessage>
     */
    public static function walk(StepPhase $step, ImportContext $context, SourceReader $reader, ?string $token = null): array
    {
        $said = [];
        $cursor = null;

        do {
            $result = $step->run($context, $reader, $cursor);

            if ($token !== null && $result->messages !== []) {
                app(RecordImportMessages::class)->execute(new RecordImportMessagesInput($context->runId, $token, $result->messages, $context->actorId()));
            }

            $said = [...$said, ...$result->messages];
            $cursor = $result->cursor;
        } while ($cursor !== null);

        return $said;
    }

    /**
     * Adds addresses of one kind, as the companion's urls list gives them;
     * an item's own `kind` (the home page's) wins.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function urls(string $kind, array $items): self
    {
        foreach ($items as $item) {
            $this->urls[$kind][] = ['kind' => $kind, ...$item];
        }

        return $this;
    }

    /**
     * The settings answer: these options over the defaults, its locale and
     * its SEO plugin (`yoast`, `rank_math` or none).
     *
     * @param  array<string, mixed>  $options
     */
    public function options(array $options, string $locale = 'en_GB', ?string $seo = null): self
    {
        $this->options = $options;
        $this->locale = $locale;
        $this->seo = $seo;

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function terms(array $items): self
    {
        $this->terms = $items;

        return $this;
    }

    /**
     * One store's answer: its rules for a paged store, or the whole `plain`
     * or `rules` value of Yoast SEO Premium's and Simple 301 Redirects'.
     */
    public function redirects(string $store, mixed $answer): self
    {
        $this->redirects[$store] = $answer;

        return $this;
    }

    public function page(string $list, array $query, int $afterId, int $limit): SourcePage
    {
        $this->requests[] = ['list' => $list, 'query' => $query, 'after' => $afterId, 'limit' => $limit];
        $source = (string) ($query['source'] ?? '');

        $items = match ($list) {
            'urls' => $this->urls[(string) ($query['kind'] ?? '')] ?? [],
            'terms' => $this->terms,
            'redirects' => is_array($this->redirects[$source] ?? null) ? $this->redirects[$source] : [],
            default => throw new LogicException("The old site has no list [{$list}]."),
        };

        $items = array_values(array_filter($items, fn (array $item): bool => (int) ($item['id'] ?? 0) > $afterId));
        usort($items, fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $page = array_slice($items, 0, $limit);

        // A full page names the next, as the companion's Envelope does.
        return new SourcePage($page, count($page) >= $limit ? (int) $page[count($page) - 1]['id'] : null);
    }

    public function whole(string $list, array $query = []): array
    {
        $this->requests[] = ['list' => $list, 'query' => $query, 'after' => null, 'limit' => null];
        $source = (string) ($query['source'] ?? '');

        return match (true) {
            $list === 'settings' => [
                'api' => 1, 'home' => self::HOME, 'siteurl' => self::HOME, 'locale' => $this->locale,
                'options' => [...self::OPTIONS, ...$this->options],
                'sticky_posts' => [], 'menu_locations' => [], 'seo_plugin' => $this->seo, 'multilingual' => null,
            ],
            $list === 'languages' => ['api' => 1, 'active' => false],
            $list === 'urls' => ['api' => 1, 'items' => $this->urls[(string) ($query['kind'] ?? '')] ?? [], 'next' => null, 'limit' => 0],
            $list === 'redirects' && $source === 'yoast_premium' => ['api' => 1, 'plain' => $this->redirects[$source] ?? null, 'regex' => null],
            $list === 'redirects' && $source === 'simple_301' => ['api' => 1, 'rules' => $this->redirects[$source] ?? null],
            default => throw new LogicException("The old site has no whole answer [{$list}]."),
        };
    }

    /**
     * The kind and the after ID of every request to the urls list, in order.
     *
     * @return list<string>
     */
    public function urlRequests(): array
    {
        $read = [];

        foreach ($this->requests as $request) {
            if ($request['list'] === 'urls') {
                $read[] = ($request['query']['kind'] ?? '').':'.($request['after'] ?? 'whole');
            }
        }

        return $read;
    }
}
