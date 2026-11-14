<?php

declare(strict_types=1);

namespace App\Domain\Benchmark\ImportBench;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The benchmark's synthetic WordPress site, answered in this process as
 * the Teme Companion plugin would (D4): Http::fake() hands it each request
 * the importer's own client makes, so the client runs unchanged and nothing
 * reaches a network. A page is built from the IDs asked for, never from a
 * sorted list, so it costs the same at item 49,950 as at item 1
 * (docs/benchmarks/).
 */
final class BenchmarkCompanion
{
    /** The only user the companion signs in, with any password. */
    public const string USER = 'bench1';

    /** A bcrypt hash in the form the companion sends, the same for every person. */
    private const string HASH = '$wp$2y$10$fixturefixturefixturefixturefixturefixturefixtureabc';

    /** Each list route's page size and its largest, as the plugin's own Paging::read() calls give them. */
    private const array LIMITS = [
        'users' => [200, 500], 'terms' => [500, 1000], 'media' => [200, 500], 'content' => [50, 200],
        'comments' => [500, 1000], 'redirects' => [500, 1000], 'urls' => [1000, 2000],
    ];

    /** @var array<string, int> */
    private array $served = [];

    /**
     * The addresses the urls route has given since they were last taken, as
     * kind:id, each once.
     *
     * @var array<string, true>
     */
    private array $addresses = [];

    public function __construct(private readonly BenchmarkSite $site) {}

    /**
     * The pages of this route answered so far.
     */
    public function served(string $route): int
    {
        return $this->served[$route] ?? 0;
    }

    /**
     * The distinct addresses, as kind:id, the urls route has given since the
     * last call, and forgets them. A page read again from a cursor gives some
     * a second time, and they count once.
     *
     * @return list<string>
     */
    public function takeAddresses(): array
    {
        $taken = array_keys($this->addresses);
        $this->addresses = [];

        return $taken;
    }

    /**
     * The answer to one request, for Http::fake().
     */
    public function respond(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        parse_str($url['query'] ?? '', $query);
        $path = $url['path'] ?? '/';

        if (isset($query['rest_route'])) {
            $route = (string) preg_replace('#^/teme/v1/#', '', (string) $query['rest_route']);
        } elseif (str_starts_with($path, '/wp-json/teme/v1/')) {
            $route = substr($path, strlen('/wp-json/teme/v1/'));
        } else {
            return Http::response('<html><title>Page not found</title></html>', 404, ['Content-Type' => 'text/html']);
        }

        if ($route === 'probe') {
            return Http::response(['name' => 'teme-companion', 'version' => '0.2.0', 'api' => 1, 'application_passwords' => true]);
        }

        if (! $this->signedIn($request)) {
            return Http::response(['code' => 'teme_forbidden', 'message' => 'An administrator account is required.', 'data' => ['status' => 401]], 401);
        }

        $body = match ($route) {
            'settings' => $this->settings(),
            'analyze' => $this->analysis(),
            'menus' => $this->menus(),
            'users', 'terms', 'media', 'content', 'comments', 'redirects' => $this->listed($route, $query),
            'urls' => ($query['kind'] ?? '') === 'archive' ? $this->home() : $this->listed('urls', $query),
            default => null,
        };

        if ($body === null) {
            return Http::response(['code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.', 'data' => ['status' => 404]], 404);
        }

        return Http::response($body);
    }

    /**
     * The home page, the one archive the site lists, whole.
     *
     * @return array<string, mixed>
     */
    private function home(): array
    {
        $this->addresses['home:0'] = true;

        return ['api' => 1, 'items' => [['id' => 0, 'kind' => 'home', 'url' => BenchmarkSite::ADDRESS.'/']]];
    }

    private function signedIn(Request $request): bool
    {
        $header = $request->header('Authorization')[0] ?? '';

        return str_starts_with($header, 'Basic ') && explode(':', (string) base64_decode(substr($header, 6)), 2)[0] === self::USER;
    }

    /**
     * One keyset page of a list, in the plugin's envelope: a full page names
     * the next, so the last full page is followed by an empty one.
     *
     * @param  array<string, mixed>  $query
     *
     * @return array<string, mixed>
     */
    private function listed(string $route, array $query): array
    {
        [$default, $max] = self::LIMITS[$route];
        $after = max(0, (int) ($query['after_id'] ?? 0));
        $limit = min($max, max(1, (int) ($query['limit'] ?? $default)));
        $this->served[$route] = $this->served($route) + 1;
        $items = [];

        foreach ($this->ids($route, $query, $after) as $id) {
            $item = $this->item($route, $id, $query);

            if ($item !== null) {
                $items[] = $item;

                if ($route === 'urls') {
                    $this->addresses[$item['kind'].':'.$id] = true;
                }
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return [
            'api' => 1,
            'items' => $items,
            'next' => count($items) >= $limit ? ['after_id' => (int) $items[count($items) - 1]['id']] : null,
            'limit' => $limit,
        ];
    }

    /**
     * The IDs of a list past $after, in order, as a generator: a range is
     * never built whole.
     *
     * @param  array<string, mixed>  $query
     *
     * @return iterable<int>
     */
    private function ids(string $route, array $query, int $after): iterable
    {
        $site = $this->site;

        if ($route === 'urls' && ($query['kind'] ?? 'post') === 'author') {
            return array_values(array_filter($site->authorsWithPosts(), fn (int $id): bool => $id > $after));
        }

        [$first, $last] = match (true) {
            $route === 'users' => [1, $site->people],
            $route === 'terms' => [1, $site->categories + $site->tags],
            $route === 'comments' => [1, $site->comments],
            $route === 'redirects' => [1, $site->rules],
            $route === 'media' => [1, 0],
            $route === 'urls' => match ($query['kind'] ?? 'post') {
                'term' => [1, $site->categories + $site->tags],
                'post' => [BenchmarkSite::FIRST + 1, BenchmarkSite::FIRST + $site->items],
                default => [1, 0],
            },
            default => [BenchmarkSite::FIRST + 1, BenchmarkSite::FIRST + $site->items],
        };

        return (function () use ($first, $last, $after): iterable {
            for ($id = max($first, $after + 1); $id <= $last; $id++) {
                yield $id;
            }
        })();
    }

    /**
     * One list item, or null when the query leaves it out.
     *
     * @param  array<string, mixed>  $query
     *
     * @return array<string, mixed>|null
     */
    private function item(string $route, int $id, array $query): ?array
    {
        return match ($route) {
            'users' => $this->person($id),
            'terms' => $this->term($id),
            'comments' => $this->comment($id),
            'redirects' => $this->rule($id),
            'urls' => $this->address($id, (string) ($query['kind'] ?? 'post')),
            'content' => $this->wanted($id - BenchmarkSite::FIRST, $query) ? $this->content($id - BenchmarkSite::FIRST, ($query['include'] ?? '') === 'rendered') : null,
            default => null,
        };
    }

    /**
     * Whether the query's types and the modified-after time keep item $n:
     * every item is published and none is changed after its date.
     *
     * @param  array<string, mixed>  $query
     */
    private function wanted(int $n, array $query): bool
    {
        $types = is_string($query['types'] ?? null) && $query['types'] !== '' ? explode(',', $query['types']) : null;
        $statuses = is_string($query['statuses'] ?? null) && $query['statuses'] !== '' ? explode(',', $query['statuses']) : null;
        $after = is_string($query['modified_after'] ?? null) ? $query['modified_after'] : null;

        return ($types === null || in_array($this->site->isPage($n) ? 'page' : 'post', $types, true))
            && ($statuses === null || in_array('publish', $statuses, true))
            && ($after === null || gmdate('Y-m-d H:i:s', $this->site->timestamp($n)) > $after);
    }

    /**
     * @return array<string, mixed>
     */
    private function person(int $id): array
    {
        $roles = $id === 1 ? 'administrator' : ($id <= 5 ? 'editor' : 'author');
        $posts = in_array($id, $this->site->authorsWithPosts(), true) ? max(1, intdiv($this->site->items, max(1, $this->site->people - 5))) : 0;

        return [
            'id' => $id, 'login' => "bench{$id}", 'email' => "bench{$id}@example.com", 'nicename' => "bench{$id}", 'display_name' => "Writer {$id}",
            'first_name' => 'Writer', 'last_name' => (string) $id, 'description' => '', 'url' => '', 'registered' => '2015-12-01 09:00:00',
            'roles' => [$roles], 'password_hash' => self::HASH, 'published_posts' => $posts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function term(int $id): array
    {
        $category = $id <= $this->site->categories;
        $slug = $category ? "topic-{$id}" : "tag-{$id}";

        return [
            'id' => $id, 'term_id' => $id, 'taxonomy' => $category ? 'category' : 'post_tag', 'name' => $category ? "Topic {$id}" : "Tag {$id}",
            'slug' => $slug, 'description' => '', 'parent' => $category && $id % 10 === 0 && $id > 1 ? $id - 1 : 0, 'count' => 0,
            'link' => BenchmarkSite::ADDRESS.($category ? '/category/'.$this->site->categoryPath($id) : "/tag/{$slug}").'/', 'seo' => null,
        ];
    }

    /**
     * Item $n: the same three paragraphs as blocks, and, when asked, as the
     * HTML WordPress draws, for the fidelity check.
     *
     * @return array<string, mixed>
     */
    private function content(int $n, bool $rendered): array
    {
        $site = $this->site;
        $page = $site->isPage($n);
        $slug = $site->slug($n);
        $date = gmdate('Y-m-d H:i:s', $site->timestamp($n));
        $link = $n % 10 === 3 && $n < $site->items ? ' Read <a href="'.$site->permalink($n + 1).'">the next one</a> too.' : '';
        $paragraphs = ["Item {$n} begins with a harbour at dawn.{$link}", "The tide tables for item {$n} were printed in black and white.", 'Nothing else happened on that day, which suited everyone.'];
        $blocks = array_map(fn (string $text): array => ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => "<p>{$text}</p>", 'innerContent' => ["<p>{$text}</p>"]], $paragraphs);
        $terms = $page ? [] : [
            'category' => [1 + $n % $site->categories],
            'post_tag' => array_values(array_unique([$site->categories + 1 + $n % $site->tags, $site->categories + 1 + (7 * $n) % $site->tags])),
        ];

        $item = [
            'id' => BenchmarkSite::FIRST + $n, 'type' => $page ? 'page' : 'post', 'status' => 'publish', 'slug' => $slug, 'title' => ucfirst(str_replace('-', ' ', $slug)),
            'date' => $date, 'date_gmt' => $date, 'modified_gmt' => $date, 'author' => $site->authorOf($n), 'parent' => 0, 'menu_order' => 0,
            'comment_status' => $page ? 'closed' : 'open', 'ping_status' => 'closed', 'password' => '', 'sticky' => false, 'format' => null, 'template' => null,
            'featured_media' => null, 'terms' => $terms, 'excerpt' => '', 'content' => '', 'has_blocks' => true, 'blocks' => $blocks,
            'permalink' => $site->permalink($n), 'old_slugs' => $n % 5 === 0 ? ['old-'.$slug] : [], 'old_dates' => [], 'seo' => null,
            'comment_count' => $site->commentsOn($n),
        ];

        if ($rendered) {
            $item['rendered'] = implode('', array_map(fn (string $text): string => "<p>{$text}</p>", $paragraphs));
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function comment(int $id): array
    {
        $site = $this->site;
        $posts = $site->postCount();
        $k = ($id - 1) % $posts;
        $n = $site->postNumber($k);

        return [
            'id' => $id, 'post_id' => BenchmarkSite::FIRST + $n, 'parent' => $id % 4 === 0 && $id > $posts ? $id - $posts : 0, 'type' => 'comment', 'status' => '1',
            'author_name' => 'Reader '.($id % 500), 'author_email' => 'reader'.($id % 500).'@example.com', 'author_url' => '', 'author_ip' => '203.0.113.'.($id % 250 + 1),
            'user_agent' => 'Mozilla/5.0', 'date_gmt' => gmdate('Y-m-d H:i:s', $site->timestamp($n) + 3600 + intdiv($id - 1, $posts) * 3600),
            'content' => "Comment {$id} on item {$n}: a good read.", 'user_id' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(int $id): array
    {
        $n = ($id - 1) % max(1, $this->site->items) + 1;

        return [
            'id' => $id, 'url' => "/archive/{$id}/", 'match_type' => 'url', 'action_type' => 'url', 'action_code' => 301,
            'action_data' => $this->site->permalink($n), 'regex' => false, 'status' => 'enabled',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function address(int $id, string $kind): ?array
    {
        $site = $this->site;

        return match ($kind) {
            'post' => ['id' => $id, 'kind' => 'post', 'type' => $site->isPage($id - BenchmarkSite::FIRST) ? 'page' : 'post', 'url' => $site->permalink($id - BenchmarkSite::FIRST)],
            'term' => ['id' => $id, 'kind' => 'term', 'taxonomy' => $id <= $site->categories ? 'category' : 'post_tag', 'url' => $this->term($id)['link']],
            'author' => ['id' => $id, 'kind' => 'author', 'url' => BenchmarkSite::ADDRESS."/author/bench{$id}/"],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'api' => 1, 'home' => BenchmarkSite::ADDRESS, 'siteurl' => BenchmarkSite::ADDRESS, 'locale' => 'en_US',
            'options' => [
                'blogname' => 'Benchmark Harbour', 'blogdescription' => 'A synthetic site', 'timezone_string' => 'UTC', 'gmt_offset' => '0',
                'date_format' => 'j F Y', 'time_format' => 'H:i', 'start_of_week' => '1', 'posts_per_page' => '10', 'posts_per_rss' => '10',
                'rss_use_excerpt' => '0', 'show_on_front' => 'posts', 'page_on_front' => '0', 'page_for_posts' => '0',
                'permalink_structure' => '/%postname%/', 'category_base' => '', 'tag_base' => '', 'default_category' => '1',
                'default_post_format' => '0', 'site_icon' => '0', 'blog_public' => '1', 'default_comment_status' => 'open',
                'default_ping_status' => 'closed', 'comment_registration' => '0', 'require_name_email' => '1', 'close_comments_for_old_posts' => '0',
                'close_comments_days_old' => '14', 'thread_comments' => '1', 'thread_comments_depth' => '5', 'page_comments' => '0',
                'comments_per_page' => '50', 'comment_order' => 'asc', 'comment_moderation' => '0', 'comment_previously_approved' => '1',
                'comment_max_links' => '2', 'show_avatars' => '1', 'comments_notify' => '1', 'moderation_notify' => '1',
            ],
            'sticky_posts' => [], 'menu_locations' => ['primary' => 3], 'seo_plugin' => null, 'multilingual' => null,
        ];
    }

    /**
     * The site report, with this site's counts.
     *
     * @return array<string, mixed>
     */
    private function analysis(): array
    {
        $site = $this->site;
        $pages = intdiv($site->items, 10);
        $none = ['future' => 0, 'draft' => 0, 'pending' => 0, 'private' => 0, 'trash' => 0, 'auto-draft' => 0, 'inherit' => 0];

        return [
            'api' => 1,
            'report' => [
                'report_version' => 1, 'plugin_version' => '0.2.0', 'include_examples' => false,
                'started_at' => '2026-10-01T09:20:00+00:00', 'generated_at' => '2026-10-01T09:30:00+00:00',
                'sections' => [
                    'environment' => ['home' => BenchmarkSite::ADDRESS, 'siteurl' => BenchmarkSite::ADDRESS],
                    'content_types' => [
                        'post_types' => [
                            'post' => ['label' => 'Posts', 'builtin' => true, 'public' => true, 'counts' => ['publish' => $site->items - $pages, ...$none]],
                            'page' => ['label' => 'Pages', 'builtin' => true, 'public' => true, 'counts' => ['publish' => $pages, ...$none]],
                            'attachment' => ['label' => 'Media', 'builtin' => true, 'public' => true, 'counts' => ['publish' => 0, 'private' => 0, 'inherit' => 0]],
                        ],
                        'taxonomies' => [
                            'category' => ['label' => 'Categories', 'builtin' => true, 'public' => true, 'terms' => $site->categories],
                            'post_tag' => ['label' => 'Tags', 'builtin' => true, 'public' => true, 'terms' => $site->tags],
                        ],
                    ],
                    'users' => ['total' => $site->people, 'by_role' => ['administrator' => 1, 'editor' => min(4, max(0, $site->people - 1)), 'author' => max(0, $site->people - 5)], 'custom_roles' => []],
                    'comments' => ['total' => $site->comments, 'by_status_and_type' => ['1|comment' => $site->comments]],
                    'media' => ['total' => 0],
                    'menus' => ['menus' => [['term_id' => 3, 'items' => 5]], 'registered_locations' => ['primary'], 'assigned_locations' => ['primary' => 3], 'navigation_posts' => 0],
                    'post_states' => ['page_templates' => ['default' => $pages], 'revisions' => 0],
                    'widgets' => ['sidebars' => ['wp_inactive_widgets' => 0, 'sidebar-1' => 0]],
                    'seo' => ['plugins' => ['yoast' => null, 'yoast_premium' => null, 'rank_math' => null, 'aioseo' => null, 'seopress' => null, 'the_seo_framework' => null]],
                    'content' => ['html' => ['images' => ['total' => 0, 'own_uploads' => 0, 'external_hosts' => []]]],
                ],
                'errors' => [],
            ],
        ];
    }

    /**
     * One menu of five items, at the theme's first place.
     *
     * @return array<string, mixed>
     */
    private function menus(): array
    {
        $site = $this->site;
        $item = fn (int $id, string $type, string $object, int $objectId, string $url, string $label): array => [
            'id' => $id, 'parent' => 0, 'order' => $id - 100, 'type' => $type, 'object' => $object, 'object_id' => $objectId, 'url' => $url, 'label' => $label,
            'label_is_custom' => $type === 'custom', 'target' => '', 'attr_title' => '', 'classes' => [], 'xfn' => '', 'invalid' => false,
        ];

        return [
            'api' => 1,
            'menus' => [['id' => 3, 'name' => 'Main', 'slug' => 'main', 'items' => [
                $item(101, 'taxonomy', 'category', 1, BenchmarkSite::ADDRESS.'/category/topic-1/', 'Topic 1'),
                $item(102, 'post_type', 'page', BenchmarkSite::FIRST + 10, $site->permalink(10), 'Page 10'),
                $item(103, 'custom', 'custom', 103, BenchmarkSite::ADDRESS.'/', 'Home'),
                $item(104, 'custom', 'custom', 104, 'https://example.org/', 'Elsewhere'),
                $item(105, 'taxonomy', 'post_tag', $site->categories + 1, BenchmarkSite::ADDRESS.'/tag/tag-'.($site->categories + 1).'/', 'Tag'),
            ]]],
            'registered_locations' => ['primary' => 'Primary Menu'],
            'assigned_locations' => ['primary' => 3],
            'navigation' => [],
        ];
    }
}
