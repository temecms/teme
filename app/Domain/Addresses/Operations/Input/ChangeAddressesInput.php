<?php

/**
 * What ChangeAddresses needs: the patterns and bases to set, the author base,
 * the home page, each type's archive page's address, and who is asking. A
 * public type or taxonomy left out of the patterns or bases keeps its current
 * value, and so does the author base or a home page field given as null:
 * Settings › Addresses sends no home page, and Reading's Home page sends
 * nothing else.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Addresses\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ChangeAddressesInput implements OperationInput
{
    /**
     * @param  array<string, string>  $patterns  type key => pattern
     * @param  array<string, string>  $bases  taxonomy key => base, possibly ''
     * @param  array<string, string>  $archives  type key => its archive page's address; a type left out keeps its own
     */
    public function __construct(
        public array $patterns,
        public array $bases,
        public ?string $authorBase,
        public ?string $frontShows,
        public ?string $frontPageId,
        public ?string $postsPageId,
        public string $actorId,
        public array $archives = [],
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
