<?php

/**
 * Chooses a page's template (study B §3d): at most three named candidates
 * for its kind, from the most specific down, and the first that exists
 * wins. The theme's `index` is the floor every kind but `error` falls to;
 * an error falls to core's own error view, never the theme's index.
 * `comments` and `respond` fall to core's own page instead.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

use Illuminate\Support\Facades\View;

final class TemplateResolver
{
    /** Core's error page: outside `theme::`, so no theme composer runs on it. */
    public const string CORE_ERROR = 'teme::fallback.templates.error';

    /** Kinds whose page is core's own when the theme draws none, never the theme's index, which draws nothing from $page (1.8b's D10). */
    public const array CORE_FLOORS = ['comments', 'respond', 'protected'];

    public function __construct(private readonly ActiveTheme $active) {}

    public function resolve(ViewIntent $intent): Resolution
    {
        // Reading the active theme points `theme::` at it, or at core's fallback.
        $fallback = $this->active->get() === null;
        $candidates = array_map(fn (string $name): array => ['name' => $name, 'exists' => View::exists('theme::'.$name)], $this->candidates($intent));

        foreach ($candidates as $candidate) {
            if ($candidate['exists']) {
                return new Resolution('theme::'.$candidate['name'], $candidates, $fallback);
            }
        }

        if ($intent->kind === 'error') {
            return new Resolution(self::CORE_ERROR, $candidates, true);
        }

        if (in_array($intent->kind, self::CORE_FLOORS, true)) {
            return new Resolution("teme::fallback.templates.{$intent->kind}", $candidates, true);
        }

        return new Resolution('theme::templates.index', $candidates, $fallback);
    }

    /**
     * The template names to try for this page, most specific first; never `index`.
     *
     * @return list<string>
     */
    public function candidates(ViewIntent $intent): array
    {
        $names = match ($intent->kind) {
            'single' => [
                ...($intent->template !== null && $intent->template !== '' && $intent->template !== 'default' && $intent->type !== null ? ["single-{$intent->type}-{$intent->template}"] : []),
                ...($intent->type !== null ? ["single-{$intent->type}"] : []),
                'single',
            ],
            'archive' => [...($intent->type !== null ? ["archive-{$intent->type}"] : []), 'archive'],
            'taxonomy' => [
                ...($intent->taxonomy !== null && $intent->term !== null ? ["taxonomy-{$intent->taxonomy}-{$intent->term}"] : []),
                ...($intent->taxonomy !== null ? ["taxonomy-{$intent->taxonomy}"] : []),
                'archive',
            ],
            'author', 'date', 'search' => [$intent->kind, 'archive'],
            'error' => [...($intent->code !== null ? ["error-{$intent->code}"] : []), 'error'],
            default => [$intent->kind],
        };

        return array_map(fn (string $name): string => 'templates.'.$name, $names);
    }
}
