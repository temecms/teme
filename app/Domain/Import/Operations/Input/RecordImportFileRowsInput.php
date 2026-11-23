<?php

/**
 * What `RecordImportFileRows` needs: the file, the item its part of the
 * read started at and the one after its last, the rows read, what the report
 * counts from them, the channel's facts, and who reads it (null for the operator).
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class RecordImportFileRowsInput implements OperationInput
{
    /**
     * @param  list<array{list: string, id: int, payload: array<string, mixed>}>  $rows
     * @param  array{seo: list<string>, images: array{total: int, own: int, hosts: array<string, int>}, dropped: list<array{post_id: int, key: string}>}  $facts
     * @param  array{siteAddress: string|null, version: string, madeAt: string|null}  $channel
     */
    public function __construct(
        public string $fileId,
        public int $fromItem,
        public int $toItem,
        public array $rows,
        public array $facts,
        public array $channel,
        public ?string $actorId = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
