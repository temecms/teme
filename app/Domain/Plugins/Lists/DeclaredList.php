<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Lists;

/**
 * One list a plugin declares for its settings page, read from its manifest's
 * `contributes.lists` (docs/proposals/plugin-sockets-1.md).
 */
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
