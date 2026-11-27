<?php

/**
 * `<x-teme::document>`: core's document shell around every public page, so a
 * template writes only its own markup.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Cache\SignedInMarker;
use App\Domain\Cards\CardUsage;
use App\Domain\Design\Contrast;
use App\Domain\Design\SiteDesign;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Site\Document\DocumentHead;
use App\Domain\Site\Document\DocumentMeta;
use App\Domain\Site\Document\DocumentPage;
use App\Domain\Site\Document\Fragment;
use App\Domain\Site\Document\JsonLd;
use App\Domain\Site\Document\Placement;
use App\Domain\Site\Document\Placements;
use App\Domain\Site\DocumentTitle;
use App\Domain\Site\Policy\PagePolicy;
use App\Domain\Site\SiteLanguage;
use App\Domain\Site\ViewModels\ArticleView;
use App\Domain\Themes\ActiveTheme;
use App\Domain\Themes\ComposerRegistry;
use App\Domain\Themes\ThemeAssets;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Component;

/**
 * `<x-teme::document>`: core's document shell around every public page,
 * so a template writes only its own markup. It writes the `lang`
 * and `dir`,
 * the colour scheme, the title, the head's typed entries (DocumentMeta, the
 * robots value among them), the one preloaded font, `@font-face` rules from
 * the manifest's fonts, the theme's tokens and its dark ones, with the
 * site's accent, lightened for the dark scheme, the theme's assets, the
 * styles of the cards the page drew, the page's JSON-LD graph, the three
 * placements, with their fragments, the skip link and its style, the
 * speculation rules, and core's small script, which in a browser that
 * signed in asks who is reading and adds the "Edit" link.
 * It reads the page from ComposerRegistry, where PublicPage started it,
 * never the template's variables.
 */
final class Document extends Component
{
    /**
     * The site's own pages are prerendered as a reader heads for them; never
     * the admin, core's endpoints, or an address with a query string, as
     * WordPress leaves out, since a search page prerendered on the way would
     * use up the reader's search limit.
     */
    private const array SPECULATION = ['prerender' => [[
        'where' => ['and' => [
            ['href_matches' => '/*'],
            ['not' => ['href_matches' => '/admin/*']],
            ['not' => ['href_matches' => '/_teme/*']],
            ['not' => ['href_matches' => '/*\?(.+)']],
        ]],
        'eagerness' => 'moderate',
    ]]];

    public string $lang;

    /** The direction the page's language runs, `ltr` or `rtl`. */
    public string $dir;

    /** Light, dark, or auto for the reader's own: the theme's `color_scheme`. */
    public string $scheme;

    public string $title;

    /** @var array{href: string, type: string|null}|null */
    public ?array $preload;

    public string $tokens;

    public string $assets;

    public ?string $speculation;

    /** Where core's script asks who is reading (/_teme/session). */
    public string $session;

    /** The cookie core's script looks for before it asks. */
    public string $marker;

    /** @var list<string> the cards whose styles the theme replaces */
    private array $replacedCards;

    /** The page being drawn, as the head's and the placements' contributors see it. */
    private DocumentPage $page;

    /** The head's entries, gathered once (head()). */
    private ?DocumentHead $head = null;

    public function __construct(
        ComposerRegistry $composers,
        ActiveTheme $active,
        SiteLanguage $language,
        DocumentTitle $titles,
        ThemeAssets $assets,
        SiteDesign $design,
        private readonly CardUsage $usage,
        private readonly DocumentMeta $meta,
        private readonly Placements $placements,
    ) {
        $model = $composers->model();
        $this->page = new DocumentPage($composers->intent(), $model);
        $manifest = $active->get()?->manifest;
        $code = $active->get()?->code;

        // The outside hosts the theme declared, by kind, for the page's policy (I-3375).
        foreach ($manifest?->csp() ?? [] as $kind => $origins) {
            if (isset(PagePolicy::KINDS[$kind])) {
                app(PagePolicy::class)->allowOrigins(PagePolicy::KINDS[$kind], $origins);
            }
        }

        // An article's own language; any other page's is the one its address chose (I-3360), the default's when nothing chose one.
        $this->lang = $model instanceof ArticleView ? $language->forContent($model->id) : app(PageLanguage::class)->tag();
        $this->dir = app(SiteLanguages::class)->direction($this->lang);
        $this->title = $titles->for($model, $composers->intent()->isFront ?? false);
        $chosen = $design->style();
        $variation = $chosen === null ? null : ($manifest?->styles()[$chosen] ?? null);
        $preloadFile = $variation['fonts'] ?? $manifest?->preloadedFont()['file'] ?? null;
        $this->preload = $this->preloadOf($code, $preloadFile);
        $this->scheme = $design->scheme();
        $brand = $design->brand();
        $useBrand = $brand !== '' && $design->usesBrandAsAccent();
        $this->tokens = $this->styleOf(
            $variation['tokens'] ?? $manifest?->tokens() ?? [],
            $variation['tokens_dark'] ?? $manifest?->tokensDark() ?? [],
            $useBrand ? Contrast::accentSchemes($brand, $manifest) : null,
            $variation['custom_tokens'] ?? $manifest?->customTokens() ?? [],
            $manifest?->customTokensDark() ?? [],
            $code,
            $manifest?->fonts() ?? [],
            $brand === '' ? null : $brand,
        );
        $this->assets = $assets->tags();
        $this->replacedCards = $manifest?->replacedCards() ?? [];
        $this->speculation = ($manifest?->speculation() ?? true) && ! SignedInMarker::present(request())
            ? (string) json_encode(self::SPECULATION, JSON_UNESCAPED_SLASHES)
            : null;
        $this->session = route('teme.session');
        $this->marker = SignedInMarker::COOKIE;
    }

    public function render(): View
    {
        return view('teme::components.document');
    }

    /**
     * The page's head entries, gathered the first time the view asks, after
     * the template's markup is drawn, so a contributor may read what it drew,
     * as cardStyles() does for a region's cards. Each entry escapes itself.
     */
    public function head(): DocumentHead
    {
        return $this->head ??= $this->meta->for($this->page);
    }

    /**
     * The text of the page's one JSON-LD script, or null when nothing
     * gave a node.
     */
    public function jsonLd(): ?string
    {
        $graph = $this->head()->graph;

        return $graph === [] ? null : JsonLd::encode($graph);
    }

    /**
     * One placement's fragments, `head`, `body-start` or `body-end`, written as given.
     *
     * @return list<Fragment>
     */
    public function fragments(string $placement): array
    {
        return $this->placements->for(Placement::from($placement), $this->page);
    }

    /**
     * @return array{href: string, type: string|null}|null
     */
    private function preloadOf(?string $code, ?string $file): ?array
    {
        if ($code === null || $file === null) {
            return null;
        }

        $type = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            default => null,
        };

        // Served from the theme's public folder, which teme:themes:link publishes.
        return ['href' => asset("themes/{$code}/{$file}"), 'type' => $type];
    }

    /**
     * The theme's tokens as custom properties on :root, then its dark ones
     * for the dark scheme, chosen or the reader's own, or '' for
     * none. With the site's accent set, each scheme takes its pair, the dark
     * one lightened, in place of the theme's accent and on-accent. A theme's
     * own tokens (issue 370) follow the core list in each scheme. Each fonts
     * entry becomes an `@font-face` rule (issue 372); a theme that writes its
     * own faces leaves fonts out.
     *
     * @param  array<string, string>  $tokens
     * @param  array<string, string>  $tokensDark
     * @param  array{light: array{accent: string, onAccent: string, ratio: float}, dark: array{accent: string, onAccent: string, ratio: float}|null}|null  $schemes
     * @param  array<string, string>  $custom
     * @param  array<string, string>  $customDark
     * @param  list<array{family: string, file: string, weight: string, style: string, preload: bool}>  $fonts
     * @param  string|null  $brand  the site's brand colour as `--teme-brand` (issue 261)
     */
    private function styleOf(array $tokens, array $tokensDark, ?array $schemes, array $custom = [], array $customDark = [], ?string $code = null, array $fonts = [], ?string $brand = null): string
    {
        $faces = $this->fontFaces($code, $fonts);
        $lightCore = $schemes === null ? $tokens : $this->withAccent($tokens, $schemes['light']);
        if ($brand !== null) {
            $lightCore['brand'] = $brand;
        }
        $light = $this->declarations([...$lightCore, ...$custom]);
        $darkCore = $tokensDark === [] && $customDark === [] && $brand === null ? [] : ($schemes === null || $schemes['dark'] === null ? $tokensDark : $this->withAccent($tokensDark, $schemes['dark']));
        if ($brand !== null) {
            $darkCore['brand'] = $brand;
        }
        $dark = $this->declarations([...$darkCore, ...$customDark]);
        $style = $faces.($light === '' ? '' : ':root{'.$light.'}');

        if ($dark !== '') {
            $style .= ':root[data-scheme="dark"]{'.$dark.'}@media (prefers-color-scheme: dark){:root[data-scheme="auto"]{'.$dark.'}}';
        }

        return $style;
    }

    /**
     * `@font-face` rules for the theme's fonts (issue 372), or '' when it
     * declares none. Family is quoted; src is the same-origin theme address.
     *
     * @param  list<array{family: string, file: string, weight: string, style: string, preload: bool}>  $fonts
     */
    private function fontFaces(?string $code, array $fonts): string
    {
        if ($code === null || $fonts === []) {
            return '';
        }

        $rules = [];

        foreach ($fonts as $font) {
            if (preg_match('/[;{}<>]/', $font['family'].$font['weight'].$font['style'].$font['file']) === 1) {
                continue;
            }

            $rules[] = '@font-face{font-family:"'.$font['family'].'";font-weight:'.$font['weight'].';font-style:'.$font['style'].';src:url('.asset("themes/{$code}/{$font['file']}").') format("woff2");font-display:swap}';
        }

        return implode('', $rules);
    }

    /**
     * The tokens with this accent and the text colour it takes in place of
     * the theme's own two, where they stand, or at the end.
     *
     * @param  array<string, string>  $tokens
     * @param  array{accent: string, onAccent: string, ratio: float}  $pair
     *
     * @return array<string, string>
     */
    private function withAccent(array $tokens, array $pair): array
    {
        $tokens['accent'] = $pair['accent'];
        $tokens['on-accent'] = $pair['onAccent'];

        return $tokens;
    }

    /**
     * Tokens as custom-property declarations, `--teme-{name}:{value}` joined
     * by `;`, or '' for none.
     *
     * @param  array<string, string>  $tokens
     */
    private function declarations(array $tokens): string
    {
        $declarations = [];

        foreach ($tokens as $name => $value) {
            // The validator refused these already; a theme cache from before it can't end the rule or the tag.
            if (preg_match('/[;{}<>]/', $value) !== 1) {
                $declarations[] = "--teme-{$name}:{$value}";
            }
        }

        return implode(';', $declarations);
    }

    /**
     * Core's stylesheet for each card the page drew, unless the theme styles
     * that card itself; a card core has no stylesheet for brings its own.
     * A method, not a property: Blade builds the component before it renders
     * the slot, and a card first drawn there, as a region's is, is known only
     * when the view draws the head, after the slot.
     *
     * @return list<array{name: string, href: string}>
     */
    public function cardStyles(): array
    {
        $styles = [];

        foreach (array_diff($this->usage->names(), $this->replacedCards) as $name) {
            $entry = "resources/css/cards/{$name}.css";

            if (is_file(base_path($entry))) {
                $styles[] = ['name' => $name, 'href' => Vite::asset($entry)];
            }
        }

        return $styles;
    }
}
