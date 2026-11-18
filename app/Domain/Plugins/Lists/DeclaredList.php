<?php

/**
 * One list a plugin declares for its settings page, read from its manifest's
 * `contributes.lists` (docs/proposals/plugin-sockets-1.md).
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Lists;

final readonly class DeclaredList
{
    /**
     * @param  list<array{id: string, label: string, kind: string}>  $columns
     */
    public function __construct(
        public string $owner,
        public string $id,
        public string $label,
        public ?string $description,
        public array $columns,
        public int $rows,
    ) {}
}
