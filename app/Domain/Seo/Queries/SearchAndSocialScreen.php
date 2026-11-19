<?php

/**
 * Settings › Search and social's data (I-874): the group's sections in their
 * order, each with its fields and core's words for it; the title rows with
 * the tokens each row's example is filled from; robots.txt as it is now; the
 * files search engines fetch; and whether this copy is the live site.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Seo\Queries;

use App\Domain\Feeds\FeedPath;
use App\Domain\Fields\BuiltInFields;
use App\Domain\RobotsTxt\AiTrainingCrawlers;
use App\Domain\RobotsTxt\RobotsTxt;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Seo\WholeSite;
use App\Domain\Settings\Queries\SettingsForm;
use App\Domain\Site\Head\TitleTemplate;
use App\Domain\Site\SiteDates;
use App\Domain\Site\SiteLanguage;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\Manifest;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\Taxonomy;
use App\Domain\Types\TaxonomyRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Teme\Contracts\Settings\SettingsReader;

final readonly class SearchAndSocialScreen
{
    /** Core's words under a section's settings, by section key; a section has nowhere of its own to carry them. */
    public const array NOTES = [
        'titles-and-listing' => 'A row for each content type, each taxonomy and each kind of archive: one added later gets its own. Each item can also be hidden on its own, in the editor.',
        'social-cards' => 'Each item’s own social title, description and image are set in the editor, under Search and social.',
        'site-verification' => 'For services that ask you to prove the site is yours, such as Google Search Console. Each pair becomes a meta tag on the home page, as plain text, so it can’t add code to your pages.',
        'feeds' => 'RSS, at the addresses WordPress used, so a moved site’s subscribers keep getting it.',
    ];

    /** The words in place of a title row's hide box, by row. */
    public const array ROW_NOTES = [
        'home' => 'Not hidden on its own: Discourage search engines hides the whole site. A page shown as the home page uses this row unless it has its own SEO title; a page that lists the latest posts uses its type’s row.',
        'search' => 'Always hidden: search engines are asked not to list any search.',
        'not_found' => 'Never listed: it answers “not found”.',
    ];

    /** What the search results row's example was searched for. */
    public const string SAMPLE_SEARCH = 'ferry fares';

    public function __construct(
        private SettingsForm $form,
        private SettingsReader $settings,
        private ActiveTheme $theme,
        private SiteLanguage $language,
        private PublisherScreen $publisher,
    ) {}

    /**
     * Settings › Search and social as its page needs it: the sections in
     * their order with their fields and words, the title rows with their
     * example tokens, robots.txt as last saved, the addresses search engines
     * fetch, the group's values and chosen images, and the version to save
     * against.
     *
     * @return array{
     *     description: string,
     *     live: bool,
     *     discouraged: bool,
     *     files: array{sitemap: string|null, robots: string, feed: string},
     *     sections: list<array{key: string, label: string, note: string|null, fields: list<array<string, mixed>>}>,
     *     titles: array{separator: string, themeName: string, tokens: list<string>, rows: list<array<string, mixed>>},
     *     robots: array{text: string, crawlers: list<string>},
     *     siteCodeUrl: string|null,
     *     notFoundLogUrl: string|null,
     *     values: array<string, mixed>,
     *     media: array<string, mixed>,
     *     publisher: array{kinds: list<array{value: string, label: string, group: string|null}>, siteTitle: string, personGone: bool},
     *     version: int
     * }
     */
    public function data(User $viewer): array
    {
        $form = $this->form->find(SearchAndSocial::KEY) ?? throw new RuntimeException('No settings group ['.SearchAndSocial::KEY.'] is registered.');
        $fields = array_column($form['fields'], null, 'key');
        $sections = [];

        foreach (SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS)) as $section) {
            $drawn = [];

            foreach ($section->settings() as $setting) {
                if (isset($fields[$setting->key])) {
                    $drawn[] = $fields[$setting->key];
                }
            }

            $sections[] = [
                'key' => $section->key(),
                'label' => $section->label(),
                'note' => self::NOTES[$section->key()] ?? null,
                'fields' => $drawn,
            ];
        }

        $manifest = $this->theme->get()?->manifest;
        $site = $this->settings->string('general', 'site_title');
        $tagline = $this->settings->string('general', 'tagline');
        $rows = [];

        foreach ((new TitlesAndListing)->rows() as $row) {
            $rows[] = [...$row, 'note' => self::ROW_NOTES[$row['key']] ?? null, ...$this->rowTokens($row['key'], $viewer, $site, $tagline)];
        }

        $discouraged = WholeSite::discouraged();

        return [
            'description' => $form['group']['description'],
            'live' => app()->isProduction(),
            'discouraged' => $discouraged,
            'files' => [
                'sitemap' => $discouraged ? null : url('/sitemap.xml'),
                'robots' => url('/robots.txt'),
                'feed' => url('/').(new FeedPath('', true))->url(),
            ],
            'sections' => $sections,
            'titles' => [
                'separator' => $manifest?->titleSeparator() ?? Manifest::TITLE_SEPARATOR,
                'themeName' => $manifest?->name() ?? 'the active theme',
                'tokens' => TitleTemplate::TOKENS,
                'rows' => $rows,
            ],
            'robots' => ['text' => RobotsTxt::text(), 'crawlers' => AiTrainingCrawlers::agents()],
            'siteCodeUrl' => $viewer->can('site-code.manage') ? route('settings.site-code') : null,
            'notFoundLogUrl' => $viewer->can('redirects.manage') ? route('tools.redirects.not-found') : null,
            'values' => $form['values'],
            'media' => $form['media'],
            'publisher' => $this->publisher->data(),
            'version' => $form['version'],
        ];
    }

    /**
     * A row's example tokens (I-852), as a page of its kind gives them: the
     * site's own content where there is some, sample words where there is
     * none; and, for the home row alone, its second page's (I-904).
     *
     * @return array{tokens: array<string, string>, pageTwo: array<string, string>|null}
     */
    private function rowTokens(string $row, User $viewer, string $site, string $tagline): array
    {
        [$kind, $key] = array_pad(explode(':', $row, 2), 2, '');
        $tokens = ['site' => $site, 'tagline' => $tagline, 'title' => $site];

        if ($kind === 'home') {
            $pageTwo = $tokens;
            // The form {page} takes when the total isn't known, so no total is invented.
            $pageTwo['page'] = __('Page :number', ['number' => 2]);

            return ['tokens' => $tokens, 'pageTwo' => $pageTwo];
        }

        $month = SiteDates::show(SiteDates::inSite('now'), $this->language->tag(), 'MMMM YYYY');

        $tokens = match ($kind) {
            'type' => [...$tokens, ...$this->item($key)],
            'taxonomy' => [...$tokens, ...$this->term($key)],
            'author' => [...$tokens, 'title' => $viewer->name, 'author' => $viewer->name],
            'date' => [...$tokens, 'title' => $month, 'date' => $month, 'year' => SiteDates::inSite('now')->format('Y')],
            'archive' => [...$tokens, 'title' => $this->plural($key), 'type' => $this->plural($key)],
            'search' => [...$tokens, 'title' => 'Search results for “'.self::SAMPLE_SEARCH.'”', 'search' => self::SAMPLE_SEARCH],
            default => [...$tokens, 'title' => __('Page not found')],
        };

        return ['tokens' => $tokens, 'pageTwo' => null];
    }

    /**
     * This type's plural, or its key when the registry no longer has it.
     */
    private function plural(string $type): string
    {
        $registry = app(ContentTypeRegistry::class);

        return $registry->has($type) ? $registry->get($type)->plural : $type;
    }

    /**
     * The tokens of a type's newest live item, or sample words when it has none.
     *
     * @return array<string, string>
     */
    private function item(string $type): array
    {
        $singular = app(ContentTypeRegistry::class)->get($type)->singular;
        $item = DB::table('contents')
            ->join('content_revisions', 'content_revisions.id', '=', 'contents.published_revision_id')
            ->leftJoin('users', 'users.id', '=', 'contents.author_id')
            ->where('contents.type', $type)
            ->where('contents.status', 'published')
            ->whereNull('contents.deleted_at')
            ->where('contents.published_at', '<=', now())
            ->orderByDesc('contents.published_at')
            ->orderByDesc('contents.id')
            ->first(['contents.id', 'contents.published_at', 'content_revisions.title', 'content_revisions.fields', 'users.name as author']);

        if ($item === null) {
            return ['title' => 'Example '.mb_strtolower($singular), 'type' => $singular];
        }

        $written = ((array) json_decode((string) $item->fields, true))[BuiltInFields::EXCERPT] ?? null;
        $published = SiteDates::inSite((string) $item->published_at);
        $hierarchical = array_map(fn (Taxonomy $taxonomy): string => $taxonomy->key, array_filter(app(TaxonomyRegistry::class)->all(), fn (Taxonomy $taxonomy): bool => $taxonomy->hierarchical));
        $term = DB::table('content_terms')
            ->join('terms', 'terms.id', '=', 'content_terms.term_id')
            ->where('content_terms.content_id', $item->id)
            ->where('content_terms.scope', 'published')
            ->whereIn('terms.taxonomy', $hierarchical)
            ->orderBy('terms.name')
            ->value('terms.name');

        return [
            'title' => (string) $item->title,
            'author' => (string) $item->author,
            'type' => $singular,
            'excerpt' => is_string($written) ? trim($written) : '',
            'date' => SiteDates::show($published, $this->language->tag()),
            'year' => $published->format('Y'),
            'term' => (string) $term,
        ];
    }

    /**
     * The tokens of a taxonomy's first term by name, or sample words when it has none.
     *
     * @return array<string, string>
     */
    private function term(string $taxonomy): array
    {
        $name = DB::table('terms')->where('taxonomy', $taxonomy)->orderBy('name')->value('name');
        $title = is_string($name) ? $name : 'Example '.mb_strtolower(app(TaxonomyRegistry::class)->get($taxonomy)->singular);

        return ['title' => $title, 'term' => $title];
    }
}
