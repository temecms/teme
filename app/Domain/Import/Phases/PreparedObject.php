<?php

/**
 * What a phase prepared for one object, released once the object is imported or has failed, a temporary file deleted, say.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases;

interface PreparedObject
{
    /**
     * Frees whatever was prepared; always called, in a finally.
     */
    public function release(): void;
}
