<?php

/**
 * What RunListAction needs: the plugin's code, the action's id, the person
 * who clicked it, the items it runs on, and whether it runs in bulk or on a
 * single row.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RunListActionInput implements OperationInput
{
    /**
     * @param  list<string>  $itemIds  the content IDs the action runs on
     */
    public function __construct(
        public string $code,
        public string $action,
        public string $actorId,
        public array $itemIds,
        public bool $bulk = false,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
