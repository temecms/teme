<?php

declare(strict_types=1);

namespace App\Domain\Types;

/**
 * One taxonomy, such as category or tag: its labels and admin segment,
 * whether its terms nest, and the ability that manages them (I-414). Its
 * WordPress name says which taxonomy of an imported WordPress site feeds it,
 * or none. A stored one was made in the admin or a file and lives in the
 * database; code's is locked.
 */
final readonly class Taxonomy
{
    public function __construct(
        public string $key,
        public string $singular,
        public string $plural,
        public string $segment,
        public bool $hierarchical = false,
        public bool $public = true,
        public string $ability = 'terms.manage',
        public string $icon = 'folder',
        public ?string $base = null,
        public ?string $wordpress = null,
        public bool $stored = false,
    ) {}
}
