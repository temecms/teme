<?php

/**
 * What SavePluginRecord needs: the plugin's code, the record's kind, its id
 * (null for a new one), the values as sent, and who saves.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SavePluginRecordInput implements OperationInput
{
    /**
     * @param  array<array-key, mixed>  $values
     */
    public function __construct(
        public string $code,
        public string $record,
        public ?string $id,
        public array $values,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
