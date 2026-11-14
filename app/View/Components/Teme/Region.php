<?php

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Cards\CardUsage;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\RenderContext;
use App\Domain\Regions\RegionDeclarations;
use App\Domain\Regions\Regions;
use App\Domain\Themes\ActiveTheme;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-teme::region name="after-content" />`: the site's own content a theme
 * places, such as a newsletter box after the article (study B §3e), drawn
 * from the region's published revision, its cards recorded for the page's
 * head (I-248). Nothing for a region that's empty or unpublished; outside
 * production, a note for a name the theme's manifest doesn't declare.
 */
final class Region extends Component
{
    public string $name;

    /** The region's HTML, or null when there is nothing to draw. */
    public ?string $html;

    public bool $undeclared;

    /**
     * @param  string  $name  the region's slug, as the theme's manifest declares it
     */
    public function __construct(
        ActiveTheme $active,
        RegionDeclarations $declarations,
        Regions $regions,
        DocumentRenderer $renderer,
        string $name,
    ) {
        $theme = $active->get();
        $declared = $theme !== null && array_key_exists($name, $declarations->for($theme));
        $document = $declared ? $regions->published($theme->code, $name) : null;
        $html = $document === null ? '' : $renderer->html($document, new RenderContext(app(LinkResolver::class), usage: app(CardUsage::class)));

        $this->name = $name;
        $this->html = trim($html) === '' ? null : $html;
        $this->undeclared = $theme !== null && ! $declared && ! app()->isProduction();
    }

    public function render(): View
    {
        return view('teme::components.region');
    }
}
