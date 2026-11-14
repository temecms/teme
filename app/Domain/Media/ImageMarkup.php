<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Cards\CardMedia;
use App\Domain\Site\PageWarnings;
use App\Domain\Themes\ActiveTheme;

/**
 * The one place an `<img>` is written for the public site (I-245): every
 * width variant in `srcset`, the variant nearest 1,000 px as `src`, the
 * original's width and height so nothing shifts (study Q §2), `sizes` from
 * the theme's declared image context, the focal point, and lazy loading,
 * or `fetchpriority="high"` for the one image a page loads first. The
 * editor's `render.ts` draws the same markup from the same lookup.
 */
final readonly class ImageMarkup
{
    /** The width the plain `src` aims for. */
    private const int SRC_WIDTH = 1000;

    public function __construct(
        private CardMedia $media,
        private ActiveTheme $active,
        private PageWarnings $warnings,
    ) {}

    /**
     * The `<img>` for one media item, looked up now; '' with a warning when it is missing or in the trash.
     */
    public function render(string $mediaId, string $context, bool $priority = false, ?string $alt = null, bool $decorative = false, string $class = ''): string
    {
        $item = $this->media->forIds([$mediaId])[$mediaId] ?? null;

        if ($item === null) {
            $this->warnings->add("The image {$mediaId} is missing or in the trash.");

            return '';
        }

        return $this->item($item, $context, $priority, $alt, $decorative, $class);
    }

    /**
     * The `<img>` for an item already looked up through CardMedia, as a card holds it.
     *
     * @param  array{variants: array<string, array{path: string, url: string, width: int, height: int, bytes: int}>, width: int, height: int, alt: string|null, title: string, focalX: int, focalY: int}  $item
     */
    public function item(array $item, string $context, bool $priority = false, ?string $alt = null, bool $decorative = false, string $class = ''): string
    {
        $widths = array_values(array_filter($item['variants'], fn (string $key): bool => preg_match('/^w\d+$/', $key) === 1, ARRAY_FILTER_USE_KEY));
        usort($widths, fn (array $a, array $b): int => $a['width'] <=> $b['width']);

        // No widths yet, as while variants are being made: nothing, rather than a broken image.
        if ($widths === []) {
            $this->warnings->add("An image has no sizes made yet: {$item['title']}.");

            return '';
        }

        $text = $decorative ? '' : (trim((string) $alt) !== '' ? trim((string) $alt) : (string) ($item['alt'] ?? ''));
        $attributes = [
            'src="'.e($this->nearest($widths)['url']).'"',
            'srcset="'.e(implode(', ', array_map(fn (array $variant): string => "{$variant['url']} {$variant['width']}w", $widths))).'"',
            'sizes="'.e($this->sizes($context)).'"',
        ];

        $attributes[] = 'alt="'.e($text).'"';
        $attributes[] = 'width="'.$item['width'].'"';
        $attributes[] = 'height="'.$item['height'].'"';

        if ($class !== '') {
            $attributes[] = 'class="'.e($class).'"';
        }

        if ($item['focalX'] !== 50 || $item['focalY'] !== 50) {
            $attributes[] = 'style="object-position: '.$item['focalX'].'% '.$item['focalY'].'%"';
        }

        if ($priority) {
            $this->warnings->priority();
            $attributes[] = 'fetchpriority="high" decoding="async"';
        } else {
            $attributes[] = 'loading="lazy" decoding="async"';
        }

        return '<img '.implode(' ', $attributes).'>';
    }

    /**
     * The theme's `sizes` for this context, or 100vw with a warning when it declares none.
     */
    private function sizes(string $context): string
    {
        $images = $this->active->get()?->manifest?->images() ?? [];

        if (! isset($images[$context])) {
            $this->warnings->add("The theme declares no image context \"{$context}\".");

            return '100vw';
        }

        return $images[$context];
    }

    /**
     * The variant nearest SRC_WIDTH wide, the wider of two as near.
     *
     * @param  non-empty-list<array{path: string, url: string, width: int, height: int, bytes: int}>  $widths  ascending
     *
     * @return array{path: string, url: string, width: int, height: int, bytes: int}
     */
    private function nearest(array $widths): array
    {
        $best = $widths[0];

        foreach ($widths as $variant) {
            if (abs($variant['width'] - self::SRC_WIDTH) <= abs($best['width'] - self::SRC_WIDTH)) {
                $best = $variant;
            }
        }

        return $best;
    }
}
