<?php

/**
 * What RunEditorPanelAction needs: the plugin's code, the panel and the
 * action, the item and the revision the person sees, the form's values,
 * and the person who asked.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RunEditorPanelActionInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public string $code,
        public string $panel,
        public string $action,
        public string $contentId,
        public string $revisionId,
        public array $values,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
