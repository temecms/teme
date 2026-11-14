<?php

declare(strict_types=1);

namespace App\Domain\Export\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What ExportSite needs: the folder to write, which must not exist yet; a
 * page size, for tests; and who is asking (null for the operator).
 */
final readonly class ExportSiteInput implements OperationInput
{
    public function __construct(
        public string $folder,
        public ?int $pageSize = null,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
