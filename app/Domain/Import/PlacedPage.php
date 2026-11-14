<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * What putting one imported page back under its WordPress parent did
 * (I-3363): placed it, found it already placed, or left it where it was;
 * the note's code and words when there is one; and the pages under it that
 * kept their old address.
 */
final readonly class PlacedPage
{
    public const string PLACED = 'placed';

    public const string UNCHANGED = 'unchanged';

    public const string LEFT = 'left';

    /**
     * @param  list<array{title: string, path: string, address: string, holder: string}>  $kept
     */
    public function __construct(
        public string $status,
        public ?string $code = null,
        public ?string $note = null,
        public array $kept = [],
    ) {}
}
