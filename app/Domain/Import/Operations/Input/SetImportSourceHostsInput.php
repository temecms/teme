<?php

/**
 * What `SetImportSourceHosts` needs: the import source, the other host
 * names to keep (or an empty list to clear them), and who sets them
 * (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SetImportSourceHostsInput implements OperationInput
{
    /**
     * @param  list<string>  $hosts
     */
    public function __construct(
        public string $sourceId,
        public array $hosts,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
