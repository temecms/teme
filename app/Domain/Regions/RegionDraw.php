<?php

/**
 * Draws a theme's region from its published revision, with the renderer an
 * article uses, as `<x-teme::region>` does and as the fragment a region that
 * waits for a viewport width is answered with. Drawing it notes what its
 * cards need on the page being drawn, whoever then prints the result.
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Regions;

use App\Domain\Cards\CardUsage;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\HeadingIds;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\RenderContext;
use App\Domain\Themes\Theme;

final readonly class RegionDraw
{
    public function __construct(private Regions $regions, private DocumentRenderer $renderer) {}

    /**
     * The region's HTML in the page's language, or '' when it is empty or
     * unpublished.
     *
     * @param  array{size: string|null}  $declaration  the region as its theme declares it
     */
    public function html(Theme $theme, string $name, array $declaration): string
    {
        $document = $this->regions->published($theme->code, $name);

        return $document === null ? '' : $this->renderer->html($document, new RenderContext(
            app(LinkResolver::class),
            usage: app(CardUsage::class),
            region: RegionDeclarations::cardRegion($theme->code, $name, $declaration),
            headings: app(HeadingIds::class),
        ));
    }
}
