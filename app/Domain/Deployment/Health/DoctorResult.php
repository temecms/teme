<?php

/**
 * One finding, with the key and title of the check that found it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

final readonly class DoctorResult
{
    public function __construct(
        public string $key,
        public string $title,
        public Finding $finding,
    ) {}
}
