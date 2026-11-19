<?php

/**
 * A table header cell as the site draws it: tiptap-php's, plus the cell
 * alignment TableCellNode draws (I-452).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Tiptap;

use Tiptap\Nodes\TableHeader;

final class TableHeaderNode extends TableHeader
{
    /**
     * @return array<string, mixed>
     */
    public function addAttributes(): array
    {
        return [...parent::addAttributes(), 'align' => TableCellNode::alignment()];
    }
}
