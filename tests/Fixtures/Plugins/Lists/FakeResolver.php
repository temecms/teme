<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Lists;

use App\Domain\Plugins\Doors\HostResolver;

/**
 * Answers a host's addresses from a list the test gives, so no test of the
 * outbound door touches DNS; a host it wasn't given has no address.
 */
final readonly class FakeResolver implements HostResolver
{
    /** @param  array<string, list<string>>  $answers  host => its addresses */
    private function __construct(private array $answers) {}

    /** @param  array<string, list<string>>  $answers  host => its addresses */
    public static function answering(array $answers): self
    {
        return new self($answers);
    }

    public function addresses(string $host): array
    {
        return $this->answers[$host] ?? [];
    }
}
