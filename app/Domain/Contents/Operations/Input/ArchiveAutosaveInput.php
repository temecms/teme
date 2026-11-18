<?php

/**
 * An abandoned autosave to keep as a revision. Only the operator runs it, so
 * there is no actor.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ArchiveAutosaveInput implements OperationInput
{
    public function __construct(public string $autosaveId) {}

    public function actorId(): ?string
    {
        return null;
    }
}
