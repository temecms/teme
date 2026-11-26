<?php

/**
 * What a theme's region draws on the page: its published content as HTML,
 * and what plugins place before and after it. Built once per page and
 * remembered, so asking whether a region has anything to draw and then
 * drawing it costs one render and one read, however many times a template
 * asks.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Regions;

use App\Domain\Cards\CardUsage;
use App\Domain\Cards\DocumentRenderer;
use App\Domain\Cards\LinkResolver;
use App\Domain\Cards\RenderContext;
use App\Domain\Site\Insertions\PageInsertions;
use App\Domain\Themes\ActiveTheme;
use Teme\Contracts\Insertions\PagePosition;

final readonly class RegionDrawing
{
    public function __construct(
        private ActiveTheme $active,
        private RegionDeclarations $declarations,
        private Regions $regions,
        private DocumentRenderer $renderer,
    ) {}

    /**
     * The region as the page draws it. `html` is its own content, null when
     * the region is empty or unpublished; `before` and `after` what plugins
     * place around it, '' for none, which is drawn also when it is empty;
     * `undeclared` whether the theme's manifest lacks the region while the
     * site is not in production.
     *
     * @return array{html: string|null, before: string, after: string, undeclared: bool}
     */
    public function for(string $name): array
    {
        return $this->regions->drawn($name, fn (): array => $this->build($name));
    }

    /**
     * Whether the region draws anything at all: its own content, or
     * something a plugin places before or after it.
     */
    public function hasContent(string $name): bool
    {
        $drawn = $this->for($name);

        return $drawn['html'] !== null || trim($drawn['before'].$drawn['after']) !== '';
    }

    /**
     * @return array{html: string|null, before: string, after: string, undeclared: bool}
     */
    private function build(string $name): array
    {
        $theme = $this->active->get();
        $declaration = $theme === null ? null : ($this->declarations->for($theme)[$name] ?? null);
        $declared = $declaration !== null;
        $document = $declared ? $this->regions->published($theme->code, $name) : null;
        $html = $document === null ? '' : $this->renderer->html($document, new RenderContext(app(LinkResolver::class), usage: app(CardUsage::class), region: RegionDeclarations::cardRegion($theme->code, $name, $declaration)));
        $before = '';
        $after = '';

        if ($declared) {
            $insertions = app(PageInsertions::class);

            foreach ($insertions->at(PagePosition::REGION) as $entry) {
                $at = $entry['insertion']->at;

                if (($at->args['slug'] ?? null) === $name) {
                    $drawn = $insertions->draw($entry);
                    ($at->args['where'] ?? 'after') === 'before' ? $before .= $drawn : $after .= $drawn;
                }
            }
        }

        return [
            'html' => trim($html) === '' ? null : $html,
            'before' => $before,
            'after' => $after,
            'undeclared' => $theme !== null && ! $declared && ! app()->isProduction(),
        ];
    }
}
