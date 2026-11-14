<?php

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

/**
 * One finding, with the key and title of the check that found it.
 */
final readonly class DoctorResult
{
    public function __construct(
        public string $key,
        public string $title,
        public Finding $finding,
    ) {}
}
