<?php

/**
 * What `SaveDraft` needs: which content, the new title, document and fields,
 * the revision the editor started from, and who is asking.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class SaveDraftInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $terms  term IDs, in the editor's order
     * @param  string|null  $via  the plugin code that wrote this, or null for a person
     */
    public function __construct(
        public string $contentId,
        public string $title,
        public array $document,
        public array $fields,
        public ?string $expectedRevisionId,
        public ?string $actorId,
        public array $terms = [],
        /** `save` for a plain save, `checkpoint` when an autosave is gathered in, `ai` for a suggestion a person used (its origin is `ai` too). */
        public string $kind = 'save',
        /** The revision a restore is taken from; set, the new revision's kind is `restore`. */
        public ?string $restoredFromId = null,
        public ?string $via = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
