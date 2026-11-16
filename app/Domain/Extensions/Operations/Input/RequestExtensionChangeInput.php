<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Operations\Input;

use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use Teme\Contracts\Operations\OperationInput;

/**
 * What RequestExtensionChange needs: the change, whether it is for a plugin or a theme, the package and
 * the versions it may have, where it comes from (a source kind, with a saved source or an upload when the
 * kind needs one), and who asks, or null for the operator.
 */
final readonly class RequestExtensionChangeInput implements OperationInput
{
    public function __construct(
        public ExtensionAction $action,
        public ExtensionKind $kind,
        public ?string $package = null,
        public ?string $constraint = null,
        public ?string $sourceKind = null,
        public ?string $sourceId = null,
        public ?string $uploadId = null,
        private ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
