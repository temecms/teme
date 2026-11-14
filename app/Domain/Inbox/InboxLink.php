<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

/**
 * Where an inbox item leads, kept as a route's name, its parameters and the
 * ability a reader needs, so the address is made when the item is drawn (rule 9).
 */
final readonly class InboxLink
{
    /**
     * @param  array<string, string|int>  $parameters
     */
    public function __construct(
        public string $route,
        public array $parameters = [],
        public ?string $ability = null,
    ) {}

    /**
     * Returns the link as the inbox keeps it in an item's body.
     *
     * @return array{route: string, parameters: array<string, string|int>, ability: string|null}
     */
    public function toArray(): array
    {
        return ['route' => $this->route, 'parameters' => $this->parameters, 'ability' => $this->ability];
    }
}
