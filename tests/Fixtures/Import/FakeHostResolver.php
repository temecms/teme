<?php

declare(strict_types=1);

namespace Tests\Fixtures\Import;

use App\Domain\Support\Network\HostResolver;

/**
 * Answers the address check's lookups from a list, for tests: no name is looked up.
 */
final readonly class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $hosts
     */
    public function __construct(private array $hosts) {}

    public function resolve(string $host): array
    {
        return $this->hosts[strtolower($host)] ?? [];
    }
}
