<?php

/**
 * What ExportSite needs: the folder to write, which must not exist yet; a
 * page size, for tests; and who is asking (null for the operator).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

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
