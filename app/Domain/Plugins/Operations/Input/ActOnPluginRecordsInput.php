<?php

/**
 * What ActOnPluginRecords needs: the plugin's code, the record's kind, the
 * action, the records it acts on, whether a delete was confirmed, and who acts.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ActOnPluginRecordsInput implements OperationInput
{
    /**
     * @param  list<mixed>  $ids
     */
    public function __construct(
        public string $code,
        public string $record,
        public string $action,
        public array $ids,
        public bool $confirmed,
        public string $actorId,
    ) {}

    public function actorId(): string
    {
        return $this->actorId;
    }
}
