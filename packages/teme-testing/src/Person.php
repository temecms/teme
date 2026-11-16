<?php

declare(strict_types=1);

namespace Teme\Testing;

/**
 * A person the kit made on the test's site, with the role they were given:
 * pass one to any kit method that acts as somebody.
 */
final readonly class Person
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $role,
    ) {}
}
