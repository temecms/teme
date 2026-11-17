<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

/**
 * What SettleEditorSuggestion needs: the plugin's code, the panel, the
 * suggestion's id, the item, whether the person used it, and the person.
 */
final readonly class SettleEditorSuggestionInput implements OperationInput
{
    public function __construct(
        public string $code,
        public string $panel,
        public string $suggestion,
        public string $contentId,
        public bool $used,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
