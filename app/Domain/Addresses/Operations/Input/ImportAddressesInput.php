<?php

declare(strict_types=1);

namespace App\Domain\Addresses\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ImportAddresses needs: the patterns and bases to set, the author base, the home page and each type's archive page's address, each kept as it is when null, and who is asking.
 */
final readonly class ImportAddressesInput implements OperationInput
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
        public ?string $actorId,
        public array $archives = [],
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
