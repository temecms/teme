<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases;

/**
 * What a phase prepared for one object, released once the object is imported or has failed, a temporary file deleted, say.
 */
interface PreparedObject
{
    /**
     * Frees whatever was prepared; always called, in a finally.
     */
    public function release(): void;
}
