<?php

/**
 * One of the doctor's checks (I-157): what it looks at, and what it found
 * there. Registered in the Doctor by key; the container builds it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

interface DoctorCheck
{
    /**
     * What it looks at, as a short heading: "Database role".
     */
    public function title(): string;

    /**
     * What it found: at least one finding, an Ok one when all is well.
     *
     * @return list<Finding>
     */
    public function examine(): array;
}
