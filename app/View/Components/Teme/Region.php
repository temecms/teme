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
use App\Domain\Regions\RegionDrawing;
use App\Domain\Themes\ComponentOverrides;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

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
    /** The contract a theme's override of this component is written against (docs/guides/developers/themes/components.md). */
    public const int CONTRACT = 1;

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
    public function __construct(RegionDrawing $drawing, string $name)
    {
        $drawn = $drawing->for($name);

        $this->name = $name;
        $this->html = $drawn['html'];
        $this->undeclared = $drawn['undeclared'];
        $this->before = $drawn['before'];
        $this->after = $drawn['after'];
        $this->minWidth = $drawn['minWidth'];
        $this->source = $this->minWidth === null ? '' : route('teme.region', [], false).'?'.http_build_query(['name' => $name, 'lang' => app(PageLanguage::class)->tag()]);
    }

    public function render(): View
    {
        return view(app(ComponentOverrides::class)->view('region', 'teme::components.region'));
    }
}
