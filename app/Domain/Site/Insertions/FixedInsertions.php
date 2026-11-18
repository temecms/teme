<?php

/**
 * What plugins fix to the edges of a page, as core draws it at the end of
 * the body: one per edge, the first in plugin code order, each with its
 * plugin's label and a close button; side rails only where the screen has
 * room beside the theme's content. None under a theme whose manifest says
 * `"fixed": false` (docs/plans/plugin-sockets-2.md, B2).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Insertions;

use App\Domain\Plugins\PluginRegistry;
use App\Domain\Themes\ActiveTheme;
use Teme\Contracts\Insertions\PagePosition;

final readonly class FixedInsertions
{
    /** The room kept between the content and a rail, and a rail and the screen's edge, in pixels. */
    public const int RAIL_GAP = 48;

    /** The content's width when the theme doesn't give one, in pixels. */
    private const int CONTENT = 720;

    public function __construct(
        private PageInsertions $insertions,
        private ActiveTheme $active,
        private PluginRegistry $registry,
    ) {}

    /**
     * Each edge's insertion, cleaned, with its label, its rail's width and
     * the narrowest screen it shows on; none where nothing is fixed.
     *
     * @return list<array{key: string, edge: string, width: int, minWidth: int, label: string, name: string, html: string}>
     */
    public function all(): array
    {
        $manifest = $this->active->get()?->manifest;

        if (($manifest?->raw()['fixed'] ?? true) === false) {
            return [];
        }

        $content = $manifest?->layout()['content'] ?? null;
        $content = is_int($content) && $content > 0 ? $content : self::CONTENT;
        $drawn = [];

        foreach ($this->insertions->at(PagePosition::FIXED) as $entry) {
            $at = $entry['insertion']->at;
            $edge = (string) ($at->args['edge'] ?? 'bottom');

            if (isset($drawn[$edge])) {
                continue;
            }

            $html = $this->insertions->draw($entry);

            if (trim($html) === '') {
                continue;
            }

            $width = (int) ($at->args['width'] ?? 0);
            $label = $this->label($entry['owner']);
            $drawn[$edge] = [
                'key' => $entry['insertion']->key,
                'edge' => $edge,
                'width' => $width,
                'minWidth' => $width === 0 ? 0 : $content + 2 * ($width + self::RAIL_GAP),
                'label' => $label,
                'name' => self::name($label, $edge),
                'html' => $html,
            ];
        }

        return array_values($drawn);
    }

    /**
     * The name a screen reader gives the edge's landmark: its label and its
     * edge, so two edges with one label can be told apart.
     */
    private static function name(string $label, string $edge): string
    {
        return match ($edge) {
            'top' => __(':label, at the top of the screen', ['label' => $label]),
            'left' => __(':label, at the left of the screen', ['label' => $label]),
            'right' => __(':label, at the right of the screen', ['label' => $label]),
            default => __(':label, at the bottom of the screen', ['label' => $label]),
        };
    }

    /**
     * The label the plugin declared for what it fixes to an edge, or core's.
     */
    private function label(string $owner): string
    {
        $label = ($this->registry->all()[$owner] ?? null)?->manifest?->contributes()['fixedLabel'] ?? null;

        return is_string($label) && trim($label) !== '' && mb_strlen($label) <= 24 ? $label : __('Advertisement');
    }
}
