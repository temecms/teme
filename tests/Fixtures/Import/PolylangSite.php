<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Addresses\AddressSettings;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Input\StartImportRunInput;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Themes\ActiveTheme;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * A WordPress site that uses Polylang (I-3446), answered by the fake
 * companion: WordPress's locale is Spanish, Polylang's default English;
 * seven languages, whose address prefixes are Polylang's slugs (pt for
 * Brazilian Portuguese, pe for Portugal's); a post in three languages; an
 * Italian post linked to nothing; three posts whose maps lost members; a
 * home page and its Spanish version; a category and its Italian version,
 * whose slug ends in -it; a menu per language, each with a language
 * switcher; and the site's title and tagline in Spanish.
 */
final class PolylangSite
{
    /** Polylang's slug for each Teme language the site holds, the default first. */
    public const array SLUGS = ['en' => 'en', 'es' => 'es', 'pt-BR' => 'pt', 'pt' => 'pe', 'it' => 'it', 'hi' => 'hi', 'ja' => 'ja'];

    /** The theme the old site's menus were placed in. */
    public const string THEME = 'harbour';

    public FakeCompanion $companion;

    /** @var array<int, array<string, mixed>> posts and pages by WordPress ID */
    private array $content = [];

    /**
     * Sets every route's items and returns the site. Japanese is one of
     * config/languages.php's languages here, whether or not the file
     * offers it yet.
     */
    public static function serve(): self
    {
        config(['languages.ja' => config('languages.ja') ?? ['label' => 'Japanese', 'search' => 'simple', 'native' => '日本語', 'direction' => 'ltr', 'og' => 'ja_JP']]);
        $site = new self;
        $site->companion = importFake();
        $site->set();

        return $site;
    }

    /**
     * The companion's `languages` answer for the site, as
     * docs/specs/companion-languages.md shapes it.
     *
     * @param  array<string, mixed>  $changes
     *
     * @return array<string, mixed>
     */
    public static function languages(array $changes = []): array
    {
        $language = fn (string $slug, string $locale, string $name, int $order): array => ['slug' => $slug, 'locale' => $locale, 'name' => $name, 'rtl' => false, 'order' => $order];

        return [
            'api' => 1,
            'active' => true,
            'plugin_version' => '3.7.4',
            'default' => 'en',
            'languages' => [
                $language('en', 'en_US', 'English', 0),
                $language('es', 'es_ES', 'Español', 1),
                $language('pt', 'pt_BR', 'Português do Brasil', 2),
                $language('pe', 'pt_PT', 'Português', 3),
                $language('it', 'it_IT', 'Italiano', 4),
                $language('hi', 'hi_IN', 'हिन्दी', 5),
                $language('ja', 'ja', '日本語', 6),
            ],
            'options' => ['force_lang' => 1, 'hide_default' => true, 'rewrite' => true, 'redirect_lang' => false, 'browser' => true, 'media_support' => false],
            'post_types' => ['post', 'page'],
            'taxonomies' => ['category', 'post_tag'],
            'menus' => [self::THEME => ['primary' => ['en' => 3, 'es' => 4]]],
            'strings' => [
                'es' => [
                    ['name' => 'Site Title', 'translation' => 'La Gaceta del Puerto'],
                    ['name' => 'Tagline', 'translation' => 'Noticias del muelle'],
                    ['name' => 'title-home-wpseo', 'translation' => '%%sitename%% %%sep%% %%sitedesc%%'],
                    ['name' => 'title-post', 'translation' => '%%title%% %%sep%% %%date%% %%sep%% %%name%%'],
                    ['name' => 'title-tax-category', 'translation' => '%%term_title%% %%sep%% %%sitename%%'],
                    ['name' => 'Read more', 'translation' => 'Leer más'],
                ],
            ],
            ...$changes,
        ];
    }

    /**
     * The choices a person would make on Tools › Import: the menu's
     * location at the theme's first place.
     *
     * @return array<string, mixed>
     */
    public function choices(): array
    {
        $places = array_keys(app(ActiveTheme::class)->get()?->manifest?->navigations() ?? []);

        return ['menu_locations' => ['primary' => $places[0] ?? ''], 'outside_images' => []];
    }

    /**
     * Starts an import of the source and works the database queue in this
     * process until it is empty, as a worker would, one job at a time. The
     * run, fresh.
     */
    public function import(ImportSource $source, ImportRunKind $kind = ImportRunKind::Full): ImportRun
    {
        config(['queue.default' => 'database', 'queue.connections.database-long.driver' => 'database']);

        /** @var array{runId: string} $started */
        $started = scElevated(fn (): array => app(StartImportRun::class)->execute(new StartImportRunInput($source->id, $kind, ['pause_ms' => 0], $this->choices())));

        for ($jobs = 0; $jobs < 2000 && DB::table('jobs')->exists(); $jobs++) {
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'long,default', '--once' => true, '--sleep' => 0]);
        }

        // The run changed the site's settings in this process: a reader's request meets them fresh.
        app()->forgetInstance(AddressSettings::class);
        app()->forgetInstance(SiteLanguages::class);
        app()->forgetScopedInstances();

        return ImportRun::query()->findOrFail($started['runId']);
    }

    /**
     * Replaces one route's answer, the rest of the site unchanged.
     *
     * @param  array<int|string, mixed>  $answer
     */
    public function with(string $route, array $answer): self
    {
        $this->companion->items($route, $answer);

        return $this;
    }

    /**
     * Sets every route's items from the site as it stands.
     */
    private function set(): void
    {
        $this->companion
            ->items('users', [[
                'id' => 1, 'login' => 'ada', 'email' => 'ada@old.example', 'nicename' => 'ada', 'display_name' => 'Ada Lovelace',
                'first_name' => '', 'last_name' => '', 'description' => '', 'url' => '', 'registered' => '2019-03-04 09:12:00',
                'roles' => ['administrator'], 'password_hash' => '$P$Bfixturefixturefixturefixture01', 'published_posts' => 8,
            ]])
            ->items('terms', $this->terms())
            ->items('media', [])
            ->items('content', array_values($this->content()))
            ->items('comments', [])
            ->items('urls', [])
            ->items('redirects', [])
            ->items('settings', ['locale' => 'es_ES'] + CommentImports::settings([
                'blogname' => 'The Harbour Gazette', 'blogdescription' => 'News from the quay',
                'permalink_structure' => '/%postname%/', 'show_on_front' => 'page', 'page_on_front' => '41', 'page_for_posts' => '0',
            ]))
            ->items('analyze', tiReport(['seo' => ['plugins' => ['yoast' => null]]]))
            ->items('languages', self::languages())
            ->items('menus', MenuImports::answer([
                MenuImports::menu(3, [
                    MenuImports::item(301, ['order' => 1, 'type' => 'post_type', 'object' => 'page', 'object_id' => 41, 'url' => FakeCompanion::SITE.'/', 'label' => 'Home', 'label_is_custom' => false]),
                    MenuImports::item(302, ['order' => 2, 'url' => '#pll_switcher', 'label' => 'Languages', 'pll_switcher' => [
                        'hide_if_no_translation' => 0, 'hide_current' => 1, 'force_home' => 0, 'show_flags' => 1, 'show_names' => 1, 'dropdown' => 0,
                    ]]),
                ], 'Main'),
                MenuImports::menu(4, [
                    MenuImports::item(401, ['order' => 1, 'type' => 'post_type', 'object' => 'page', 'object_id' => 42, 'url' => FakeCompanion::SITE.'/es/', 'label' => 'Inicio', 'label_is_custom' => false]),
                    MenuImports::item(402, ['order' => 2, 'url' => '#pll_switcher', 'label' => 'Idiomas', 'pll_switcher' => [
                        'hide_if_no_translation' => 0, 'hide_current' => 0, 'force_home' => 0, 'show_flags' => 0, 'show_names' => 1, 'dropdown' => 1,
                    ]]),
                ], 'Principal'),
            ], [], ['primary' => 3]));
    }

    /**
     * A category in English and Italian, the Italian one's slug ending in -it.
     *
     * @return list<array<string, mixed>>
     */
    private function terms(): array
    {
        $term = fn (int $id, string $name, string $slug, string $path, string $language, array $translations): array => [
            'id' => $id, 'term_id' => $id, 'taxonomy' => 'category', 'name' => $name, 'slug' => $slug, 'description' => '',
            'parent' => 0, 'count' => 1, 'link' => FakeCompanion::SITE.$path, 'seo' => null,
            'language' => $language, 'translations' => $translations,
        ];

        return [
            $term(5, 'News', 'news', '/category/news/', 'en', ['en' => 5, 'it' => 6]),
            $term(6, 'Notizie', 'news-it', '/it/category/news-it/', 'it', ['en' => 5, 'it' => 6]),
        ];
    }

    /**
     * Posts and pages, each in its language with Polylang's map.
     *
     * @return array<int, array<string, mixed>>
     */
    private function content(): array
    {
        if ($this->content !== []) {
            return $this->content;
        }

        $item = fn (int $id, string $type, string $slug, string $title, string $language, array $translations, string $path, array $fields = []): array => ContentImports::wpItem($id, [
            'type' => $type, 'slug' => $slug, 'title' => $title, 'author' => 1,
            'blocks' => [wpBlock('core/paragraph', "<p>{$title}.</p>")], 'content' => '',
            'permalink' => FakeCompanion::SITE.$path,
            'language' => $language, 'translations' => $translations,
            ...$fields,
        ]);
        $harbour = ['en' => 21, 'es' => 22, 'pt' => 23];
        $home = ['en' => 41, 'es' => 42];

        return $this->content = [
            21 => $item(21, 'post', 'harbour-news', 'Harbour news', 'en', $harbour, '/harbour-news/', ['terms' => ['category' => [5]]]),
            22 => $item(22, 'post', 'noticias-del-puerto', 'Noticias del puerto', 'es', $harbour, '/es/noticias-del-puerto/'),
            23 => $item(23, 'post', 'noticias-do-porto', 'Notícias do porto', 'pt', $harbour, '/pt/noticias-do-porto/'),
            24 => $item(24, 'post', 'solo-in-italiano', 'Solo in italiano', 'it', [], '/it/solo-in-italiano/', ['terms' => ['category' => [6]]]),
            // A broken map: Polylang lost each one's other members.
            25 => $item(25, 'post', 'ferries', 'Ferries', 'en', ['en' => 25, 'hi' => 26], '/ferries/'),
            26 => $item(26, 'post', 'ferry-hindi', 'Ferry', 'hi', ['hi' => 26, 'ja' => 27], '/hi/ferry-hindi/'),
            27 => $item(27, 'post', 'ferry-japanese', 'Ferī', 'ja', ['ja' => 27], '/ja/ferry-japanese/'),
            41 => $item(41, 'page', 'home', 'Home', 'en', $home, '/'),
            42 => $item(42, 'page', 'inicio', 'Inicio', 'es', $home, '/es/'),
        ];
    }
}
