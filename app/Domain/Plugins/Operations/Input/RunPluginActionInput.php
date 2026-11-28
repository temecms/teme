<?php

/**
 * What RunPluginAction needs: the plugin's code, the action's id, the
 * person who clicked it, from a plugin's own area the area's permission,
 * and for a table row the record's id so one waiting run does not block
 * another row.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RunPluginActionInput implements OperationInput
{
    public function __construct(
        public string $code,
        public string $action,
        public string $actorId,
        public ?string $permission = null,
        /** The row's id when the click came from an area table. @since next */
        public ?string $recordId = null,
        /** The record kind for that row, so core can re-read unavailable. @since next */
        public ?string $recordKind = null,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
