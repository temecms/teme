<?php

/**
 * A table cell as the site draws it: tiptap-php's, plus the alignment Tiptap
 * 3.31 gives every cell, drawn after the spans as the editor's renderer draws
 * it (I-452). A header cell shares the attribute (TableHeaderNode).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Tiptap;

use App\Domain\Cards\DocumentSchema;
use Tiptap\Nodes\TableCell;

final class TableCellNode extends TableCell
{
    /**
     * @return array<string, mixed>
     */
    public function addAttributes(): array
    {
        return [...parent::addAttributes(), 'align' => self::alignment()];
    }

    /**
     * The alignment attribute, as tiptap-php configures one.
     *
     * @return array{renderHTML: callable(object|array<string, mixed>): (array{style: string}|null)}
     */
    public static function alignment(): array
    {
        return ['renderHTML' => self::alignmentStyle(...)];
    }

    /**
     * Nothing when the cell has no alignment; a style for left, centre or
     * right. tiptap-php hands over the cell's attributes as an object, or as
     * an empty array when the stored cell has `"attrs": {}`.
     *
     * @param  object|array<string, mixed>  $attributes
     *
     * @return array{style: string}|null
     */
    public static function alignmentStyle(object|array $attributes): ?array
    {
        $align = ((array) $attributes)['align'] ?? null;

        return in_array($align, DocumentSchema::CELL_ALIGNMENTS, true) ? ['style' => "text-align: {$align}"] : null;
    }
}
