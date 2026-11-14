<?php

declare(strict_types=1);

namespace App\Domain\Import\Phases;

/**
 * A list phase that must do something before an object's transaction opens,
 * such as downloading a file: what it prepares is handed to import(), and
 * released afterwards, whatever happened.
 */
interface PreparesObjects
{
    /**
     * Writes nothing.
     *
     * @param  array<string, mixed>  $item
     *
     * @throws ImportFailed when the object can't be imported, as for a download that fails
     */
    public function prepare(SourceRef $ref, array $item, ImportContext $context): PreparedObject;
}
