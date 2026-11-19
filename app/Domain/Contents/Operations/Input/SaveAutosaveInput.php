<?php

/**
 * One person's draft as they left it a moment ago — title, document, fields
 * and terms — and the revision it was based on, to keep until a checkpoint.
 * The editor numbers its saves, so one that arrives after a newer one is
 * known as older.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SaveAutosaveInput implements OperationInput
{
    /**
     * @param  array<mixed>  $document
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $terms
     */
    public function __construct(
        public string $contentId,
        public string $title,
        public array $document,
        public array $fields,
        public array $terms,
        public ?string $basedOnRevisionId,
        public ?string $actorId,
        /** The editor session sending it, and its save's number there, which only goes up */
        public ?string $editorSession = null,
        public ?int $saveNumber = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
