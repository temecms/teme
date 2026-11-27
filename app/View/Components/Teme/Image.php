<?php

/**
 * `<x-teme::image>`: a theme's way to draw a media item, such as
 * `<x-teme::image :id="$page->lead->id" context="feature" :priority="true"
 * />`.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\View\Components\Teme;

use App\Domain\Media\ImageMarkup;
use App\Domain\Themes\ComponentOverrides;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * `<x-teme::image>`: a theme's way to draw a media item, such as
 * `<x-teme::image :id="$page->lead->id" context="feature" :priority="true" />`.
 * It is thin: ImageMarkup writes the `<img>`, so every image on the site,
 * cards included, comes out the same.
 */
final class Image extends Component
{
    /** The contract a theme's override of this component is written against (docs/guides/developers/themes/components.md). */
    public const int CONTRACT = 1;

    /**
     * @param  string  $id  the media item's ID
     * @param  string  $context  the image context the theme's manifest gives a sizes value for, such as feature
     * @param  bool  $priority  whether the image is the page's most important, loaded first
     * @param  string|null  $alt  the alt text for this use; null for the media item's own
     * @param  bool  $decorative  whether the image is decoration, drawn with empty alt text
     */
    public function __construct(
        public string $id,
        public string $context = 'content',
        public bool $priority = false,
        public ?string $alt = null,
        public bool $decorative = false,
    ) {}

    public function render(): View
    {
        return app(ComponentOverrides::class)->render('image', 'teme::components.image');
    }

    /**
     * The `<img>`, with the tag's `class` attribute passed through.
     */
    public function markup(): string
    {
        return app(ImageMarkup::class)->render($this->id, $this->context, $this->priority, $this->alt, $this->decorative, (string) $this->attributes->get('class', ''));
    }
}
