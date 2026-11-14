<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Themes\ActiveTheme;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as ApiRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * A small WordPress site with everything 1.11 brings, for the import's
 * acceptance test: three people, five terms, two files, seven posts and
 * pages, five comments, one menu and two redirect rules, with every address
 * it lists, answered by the fake companion and the files it serves.
 */
final class AcceptanceSite
{
    public const string UPLOADS = 'https://old.example/wp-content/uploads/2024/05/';

    /** The words the old site's widget drew on post 22, which the import can't keep. */
    public const string WIDGET_WORDS = 'Sign up for the Friday newsletter and never miss a sailing';

    public FakeCompanion $companion;

    /** @var array<string, string> the files the old site and the outside image serve, by host and path */
    private array $files = [];

    /** @var array<int, array<string, mixed>> posts and pages by WordPress ID */
    private array $content = [];

    /** @var array<int, string> the address each listed post or page answers at */
    private array $permalinks = [];

    /**
     * Registers the site's files and redirect rules ahead of the fake
     * companion, sets every route's items and returns the site.
     */
    public static function serve(): self
    {
        $site = new self;
        $site->files = [
            'old.example/wp-content/uploads/2024/05/harbour.jpg' => dlJpeg(640, 480),
            'old.example/wp-content/uploads/2024/05/harbour-300x200.jpg' => dlJpeg(300, 200),
            'old.example/wp-content/uploads/2024/05/boat.jpg' => dlJpeg(320, 240),
            'old.example/wp-content/uploads/2024/05/boat-300x200.jpg' => dlJpeg(300, 200),
            'images.example/gull.jpg' => dlJpeg(200, 150),
        ];
        Http::fake(fn (ApiRequest $request): ?PromiseInterface => $site->answer($request));
        $site->companion = importFake();
        importResolve([FakeCompanion::HOST => [FakeCompanion::IP], 'images.example' => ['93.184.216.40'], 'cdn.example' => ['93.184.216.41']]);
        $site->set();

        return $site;
    }

    /**
     * The choices a person would make on Tools › Import for this site: its
     * menu's location at the theme's first place, and no outside image ticked.
     *
     * @return array<string, mixed>
     */
    public function choices(): array
    {
        $places = array_keys(app(ActiveTheme::class)->get()?->manifest?->navigations() ?? []);

        return ['menu_locations' => ['primary' => $places[0] ?? ''], 'outside_images' => []];
    }

    /**
     * Starts an import of the source and works the database queue's two queues, the long one first, in this
     * process until it is empty, as a worker would: one job at a time, so
     * what a job queues after its commit (an image's variants) runs before
     * the steps that come after it. The run, fresh.
     */
    public function import(ImportSource $source, ImportRunKind $kind = ImportRunKind::Full): ImportRun
    {
        config(['queue.default' => 'database', 'queue.connections.database-long.driver' => 'database']);

        /** @var array{runId: string} $started */
        $started = scElevated(fn (): array => app(StartImportRun::class)->execute(new StartImportRunInput($source->id, $kind, ['pause_ms' => 0], $this->choices())));

        for ($jobs = 0; $jobs < 2000 && DB::table('jobs')->exists(); $jobs++) {
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'long,default', '--once' => true, '--sleep' => 0]);
        }

        // The run changed the site's address settings in this process: a reader's request meets them fresh.
        app()->forgetInstance(AddressSettings::class);
        app()->forgetScopedInstances();

        return ImportRun::query()->findOrFail($started['runId']);
    }

    /**
     * The bytes the old site serves for a file of its uploads folder, by
     * name without the extension (harbour, boat).
     */
    public function bytes(string $name): string
    {
        return $this->files['old.example/wp-content/uploads/2024/05/'.$name.'.jpg'];
    }

    /**
     * Every address on old.example that the site's `urls` lists now,
     * absolute; the file on cdn.example is left out.
     *
     * @return list<string>
     */
    public function addresses(): array
    {
        $listed = [];

        foreach ($this->urls() as $item) {
            foreach ($item['kind'] === 'attachment_file' ? array_values((array) $item['files']) : [$item['url']] as $address) {
                if (str_starts_with((string) $address, FakeCompanion::SITE.'/')) {
                    $listed[] = (string) $address;
                }
            }
        }

        return $listed;
    }

    /**
     * What the import should give each object the site lists, by
     * `{type}:{WordPress ID}`.
     *
     * @return array<string, string>
     */
    public function outcomes(): array
    {
        $created = [
            'user:1', 'user:2', 'user:3',
            'term:1', 'term:5', 'term:6', 'term:7', 'term:8',
            'attachment:31', 'attachment:32',
            'post:21', 'post:22', 'post:23', 'post:24', 'post:25', 'post:41', 'post:42',
            'comment:61', 'comment:62', 'comment:63', 'comment:64', 'comment:65',
            'menu:3',
            'redirect:redirection:1', 'redirect:redirection:2',
        ];

        return [...array_fill_keys($created, 'created'), 'post:51' => 'skipped'];
    }

    /**
     * WordPress renames post 21 to tide-times (its old slugs now tides and
     * tide-tables) and deletes post 23, as it would after both.
     */
    public function renameAndDelete(): void
    {
        $this->content[21] = [
            ...$this->content[21],
            'slug' => 'tide-times',
            'permalink' => FakeCompanion::SITE.'/tide-times/',
            'old_slugs' => ['tides', 'tide-tables'],
            'modified_gmt' => now()->utc()->format('Y-m-d H:i:s'),
        ];
        $this->permalinks[21] = FakeCompanion::SITE.'/tide-times/';
        unset($this->content[23], $this->permalinks[23]);
        $this->set();
    }

    /**
     * Sets every route's items from the site as it stands.
     */
    private function set(): void
    {
        $this->companion
            ->items('users', $this->users())
            ->items('terms', $this->terms())
            ->items('media', $this->media())
            ->items('content', array_values($this->content()))
            ->items('comments', $this->comments())
            ->items('urls', $this->urls())
            ->items('settings', $this->settings())
            ->items('analyze', $this->analysis())
            ->items('menus', MenuImports::answer([MenuImports::menu(3, $this->menuItems(), 'Main')], [], ['primary' => 3]));
    }

    /**
     * The answer to a request for a file of the old site, the outside image,
     * or a redirect store, for Http::fake(); null for anything else.
     */
    private function answer(ApiRequest $request): ?PromiseInterface
    {
        $url = parse_url($request->url());
        $path = rawurldecode((string) ($url['path'] ?? '/'));
        $key = strtolower((string) ($url['host'] ?? '')).$path;

        if (isset($this->files[$key])) {
            return Http::response($this->files[$key], 200);
        }

        if ($key === 'old.example/wp-json/teme/v1/redirects') {
            parse_str((string) ($url['query'] ?? ''), $query);

            return Http::response($this->redirects((string) ($query['source'] ?? '')));
        }

        return null;
    }

    /**
     * One redirect store's answer: Redirection's two rules, and nothing in
     * the others.
     *
     * @return array<string, mixed>
     */
    private function redirects(string $store): array
    {
        return match ($store) {
            'redirection' => ['api' => 1, 'limit' => 500, 'next' => null, 'items' => [
                ['id' => 1, 'url' => '/products/kettle/', 'match_type' => 'url', 'action_type' => 'url', 'action_code' => 301, 'action_data' => ['url' => '/about/'], 'regex' => 0, 'status' => 'enabled'],
                ['id' => 2, 'url' => '/old-feed/', 'match_type' => 'url', 'action_type' => 'url', 'action_code' => 301, 'action_data' => ['url' => '/'], 'regex' => 0, 'status' => 'enabled'],
            ]],
            'yoast_premium' => ['api' => 1, 'plain' => null, 'regex' => null],
            'simple_301' => ['api' => 1, 'rules' => null],
            default => ['api' => 1, 'limit' => 500, 'next' => null, 'items' => []],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function users(): array
    {
        $person = fn (int $id, string $login, string $name, string $role, int $posts): array => [
            'id' => $id, 'login' => $login, 'email' => "{$login}@old.example", 'nicename' => $login, 'display_name' => $name,
            'first_name' => '', 'last_name' => '', 'description' => '', 'url' => '', 'registered' => '2019-03-04 09:12:00',
            'roles' => [$role], 'password_hash' => '$P$Bfixturefixturefixturefixture0'.$id, 'published_posts' => $posts,
        ];

        return [
            $person(1, 'ada', 'Ada Lovelace', 'administrator', 1),
            $person(2, 'ben', 'Ben Carter', 'editor', 1),
            $person(3, 'cy', 'Cy Dunmore', 'author', 1),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function terms(): array
    {
        $term = fn (int $id, string $taxonomy, string $name, string $slug, int $parent, string $path): array => [
            'id' => $id, 'term_id' => $id, 'taxonomy' => $taxonomy, 'name' => $name, 'slug' => $slug, 'description' => '',
            'parent' => $parent, 'count' => 1, 'link' => FakeCompanion::SITE.$path, 'seo' => null,
        ];

        return [
            $term(1, 'category', 'Uncategorized', 'uncategorized', 0, '/category/uncategorized/'),
            $term(5, 'category', 'News', 'news', 0, '/category/news/'),
            $term(6, 'category', 'Harbour', 'harbour', 5, '/category/harbour/'),
            $term(7, 'post_tag', 'tides', 'tides', 0, '/tag/tides/'),
            $term(8, 'post_tag', 'ferries', 'ferries', 0, '/tag/ferries/'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function media(): array
    {
        $file = fn (int $id, string $name, int $parent, string $title, string $page): array => [
            'id' => $id, 'parent' => $parent, 'author' => 1, 'date_gmt' => '2024-05-06 07:08:09', 'modified_gmt' => '2024-05-06 07:08:09',
            'status' => 'inherit', 'title' => $title, 'caption' => '', 'description' => '', 'alt' => $title, 'mime' => 'image/jpeg',
            'file' => "2024/05/{$name}.jpg", 'url' => self::UPLOADS."{$name}.jpg", 'original_url' => null,
            'attachment_page' => FakeCompanion::SITE.$page,
            'metadata' => ['width' => 640, 'height' => 480, 'file' => "2024/05/{$name}.jpg", 'filesize' => 1000,
                'sizes' => ['medium' => ['file' => "{$name}-300x200.jpg", 'width' => 300, 'height' => 200, 'mime' => 'image/jpeg']]],
            'backup_sizes' => null,
        ];

        return [
            $file(31, 'harbour', 21, 'Harbour at dawn', '/tide-tables/harbour/'),
            $file(32, 'boat', 0, 'A boat', '/boat/'),
        ];
    }

    /**
     * Posts, pages and the custom type's item, each with the rendered page
     * the fidelity check reads.
     *
     * @return array<int, array<string, mixed>>
     */
    private function content(): array
    {
        if ($this->content !== []) {
            return $this->content;
        }

        $paragraph = fn (string $html): array => wpBlock('core/paragraph', "<p>{$html}</p>");
        $item = function (int $id, string $type, string $slug, string $title, string $status, array $blocks, string $rendered, array $fields = []): array {
            return ContentImports::wpItem($id, [
                'type' => $type, 'slug' => $slug, 'title' => $title, 'status' => $status, 'blocks' => $blocks,
                'content' => '', 'rendered' => $rendered,
                'permalink' => FakeCompanion::SITE.($type === 'product' ? '/products/' : '/')."{$slug}/",
                ...$fields,
            ]);
        };

        $this->content = [
            21 => $item(21, 'post', 'tide-tables', 'Tide tables', 'publish', [
                $paragraph('Low water is at six, and high water follows at noon.'),
                $paragraph('Read how <a href="'.FakeCompanion::SITE.'/ferry-news/">the ferries</a> keep to them.'),
            ], '<p>Low water is at six, and high water follows at noon.</p><p>Read how the ferries keep to them.</p>', [
                'author' => 1, 'terms' => ['category' => [5, 6], 'post_tag' => [7]], 'featured_media' => 31, 'old_slugs' => ['tides'], 'comment_count' => 2,
            ]),
            22 => $item(22, 'post', 'ferry-news', 'Ferry news', 'publish', [
                $paragraph('The ferries sail on Friday. [old_widget id="3"]'),
                wpBlock('core/image', '<figure class="wp-block-image"><img src="https://images.example/gull.jpg" alt="A gull"/></figure>'),
                $paragraph('Read <a href="'.FakeCompanion::SITE.'/about/">about the harbour</a> too.'),
            ], '<p>The ferries sail on Friday. '.self::WIDGET_WORDS.'</p><figure><img src="https://images.example/gull.jpg" alt="A gull"></figure><p>Read about the harbour too.</p>', [
                'author' => 2, 'terms' => ['category' => [5], 'post_tag' => [8]], 'comment_count' => 1,
            ]),
            23 => $item(23, 'post', 'late-night', 'Late night', 'publish', [
                wpBlock('core/html', '<div>Open until midnight.</div><script>alert("late")</script>'),
            ], '<div>Open until midnight.</div>', ['author' => 3]),
            24 => $item(24, 'post', 'next-week', 'Next week', 'draft', [$paragraph('Plans for next week.')], '<p>Plans for next week.</p>', ['author' => 1]),
            25 => $item(25, 'post', 'staff-notes', 'Staff notes', 'private', [$paragraph('Keys are in the office.')], '<p>Keys are in the office.</p>', ['author' => 2]),
            41 => $item(41, 'page', 'about', 'About', 'publish', [$paragraph('We write about the harbour.')], '<p>We write about the harbour.</p>', ['author' => 1, 'menu_order' => 1]),
            42 => $item(42, 'page', 'team', 'Our team', 'publish', [$paragraph('Ada and Ben.')], '<p>Ada and Ben.</p>', ['author' => 1, 'parent' => 41, 'menu_order' => 2, 'permalink' => FakeCompanion::SITE.'/about/team/']),
            51 => $item(51, 'product', 'kettle', 'Kettle', 'publish', [$paragraph('A kettle.')], '<p>A kettle.</p>', ['author' => 1]),
        ];
        $this->permalinks = [
            21 => FakeCompanion::SITE.'/tide-tables/', 22 => FakeCompanion::SITE.'/ferry-news/', 23 => FakeCompanion::SITE.'/late-night/',
            41 => FakeCompanion::SITE.'/about/', 42 => FakeCompanion::SITE.'/about/team/', 51 => FakeCompanion::SITE.'/products/kettle/',
        ];

        return $this->content;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function comments(): array
    {
        return [
            CommentImports::item(61, ['post_id' => 21]),
            CommentImports::item(62, ['post_id' => 21, 'parent' => 61, 'author_name' => 'Ben Guest']),
            CommentImports::item(63, ['post_id' => 22]),
            CommentImports::item(64, ['post_id' => 21, 'type' => 'pingback', 'author_name' => 'Another blog', 'content' => 'A link to this post.']),
            CommentImports::item(65, ['post_id' => 22, 'status' => 'spam', 'content' => 'Buy things now.']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return CommentImports::settings([
            'blogname' => 'The Harbour Gazette', 'blogdescription' => 'News from the quay',
            'permalink_structure' => '/%postname%/', 'page_comments' => '1', 'comments_per_page' => '1',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function analysis(): array
    {
        return tiReport(['widgets' => ['sidebars' => ['wp_inactive_widgets' => 0, 'sidebar-1' => 1, 'footer-1' => 0], 'widget_types' => ['text' => 1]]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function menuItems(): array
    {
        return [
            MenuImports::item(301, ['order' => 1, 'type' => 'taxonomy', 'object' => 'category', 'object_id' => 5, 'url' => FakeCompanion::SITE.'/category/news/', 'label' => 'News', 'label_is_custom' => false]),
            MenuImports::item(302, ['order' => 2, 'type' => 'post_type', 'object' => 'page', 'object_id' => 41, 'url' => FakeCompanion::SITE.'/about/', 'label' => 'About', 'label_is_custom' => false]),
            MenuImports::item(303, ['order' => 3, 'url' => 'https://example.org/', 'label' => 'Example']),
        ];
    }

    /**
     * Every address the site lists, by kind as the companion's urls route
     * sends them.
     *
     * @return list<array<string, mixed>>
     */
    private function urls(): array
    {
        $this->content();
        $listed = [];

        foreach ($this->permalinks as $id => $address) {
            $item = $this->content[$id];
            $listed[] = ['id' => $id, 'kind' => 'post', 'type' => $item['type'], 'url' => $address];
        }

        return [
            ...$listed,
            ['id' => 5, 'kind' => 'term', 'taxonomy' => 'category', 'url' => FakeCompanion::SITE.'/category/news/'],
            ['id' => 6, 'kind' => 'term', 'taxonomy' => 'category', 'url' => FakeCompanion::SITE.'/category/harbour/'],
            ['id' => 7, 'kind' => 'term', 'taxonomy' => 'post_tag', 'url' => FakeCompanion::SITE.'/tag/tides/'],
            ['id' => 8, 'kind' => 'term', 'taxonomy' => 'post_tag', 'url' => FakeCompanion::SITE.'/tag/ferries/'],
            ['id' => 1, 'kind' => 'author', 'url' => FakeCompanion::SITE.'/author/ada/'],
            ['id' => 31, 'kind' => 'attachment_page', 'url' => FakeCompanion::SITE.'/tide-tables/harbour/'],
            ['id' => 32, 'kind' => 'attachment_page', 'url' => FakeCompanion::SITE.'/boat/'],
            ['id' => 31, 'kind' => 'attachment_file', 'files' => ['full' => self::UPLOADS.'harbour.jpg', 'medium' => self::UPLOADS.'harbour-300x200.jpg']],
            ['id' => 32, 'kind' => 'attachment_file', 'files' => ['full' => self::UPLOADS.'boat.jpg', 'medium' => 'https://cdn.example/uploads/boat-300x200.jpg']],
            ['id' => 0, 'kind' => 'home', 'url' => FakeCompanion::SITE.'/'],
        ];
    }
}
