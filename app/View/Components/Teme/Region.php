<?php

/**
 * `<x-teme::region name="after-content" />`: the site's own content a theme
 * places, such as a newsletter box after the article, drawn from the region's
 * published revision, its cards recorded for the page's head.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Languages\PageLanguage;
use App\Domain\Regions\RegionDeclarations;
use App\Domain\Regions\RegionDraw;
use App\Domain\Site\Insertions\PageInsertions;
use App\Domain\Themes\ActiveTheme;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Teme\Contracts\Insertions\PagePosition;

/**
 * `<x-teme::region name="after-content" />`: the site's own content a theme
 * places, such as a newsletter box after the article, drawn
 * from the region's published revision, its cards recorded for the page's
 * head. Nothing for a region that's empty or unpublished; outside
 * production, a note for a name the theme's manifest doesn't declare. What
 * plugins place before or after a declared region is drawn around it, also
 * when it is empty.
 */
final class Region extends Component
{
    public string $name;

    /** The region's HTML, or null when there is nothing to draw. */
    public ?string $html;

    public bool $undeclared;

    /** The viewport width the region waits for, in pixels, or null when it is drawn in the page. */
    public ?int $minWidth = null;

    /** Where core's script fetches a region that waits for a width. */
    public string $source = '';

    /** What plugins place before the region, cleaned, or ''. */
    public string $before = '';

    /** What plugins place after the region, cleaned, or ''. */
    public string $after = '';

    /**
     * @param  string  $name  the region's slug, as the theme's manifest declares it
     */
    public function __construct(
        ActiveTheme $active,
        RegionDeclarations $declarations,
        RegionDraw $draw,
        string $name,
    ) {
        $theme = $active->get();
        $declaration = $theme === null ? null : ($declarations->for($theme)[$name] ?? null);
        $declared = $declaration !== null;
        // A region that waits for a width is drawn here all the same: its cards' styles and the hashes and hosts of its scripts are
        // noted for this page's head and policy, but only a placeholder is printed (docs/guides/developers/themes/regions.md).
        $html = $declared ? $draw->html($theme, $name, $declaration) : '';

        $this->name = $name;
        $this->html = trim($html) === '' ? null : $html;
        $this->minWidth = $this->html === null ? null : ($declaration['minWidth'] ?? null);
        $this->source = $this->minWidth === null ? '' : route('teme.region', [], false).'?'.http_build_query(['name' => $name, 'lang' => app(PageLanguage::class)->tag()]);
        $this->undeclared = $theme !== null && ! $declared && ! app()->isProduction();

        if ($declared) {
            $insertions = app(PageInsertions::class);

            foreach ($insertions->at(PagePosition::REGION) as $entry) {
                $at = $entry['insertion']->at;

                if (($at->args['slug'] ?? null) === $name) {
                    $html = $insertions->draw($entry);
                    ($at->args['where'] ?? 'after') === 'before' ? $this->before .= $html : $this->after .= $html;
                }
            }
        }
    }

    public function render(): View
    {
        return view('teme::components.region');
    }
}
