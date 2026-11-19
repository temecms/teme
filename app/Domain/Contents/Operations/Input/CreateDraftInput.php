<?php

/**
 * What `CreateDraft` needs: the content type, its title, the document and
 * fields, who is asking, and the language it is written in.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class CreateDraftInput implements OperationInput
{
    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $terms  term IDs, in the editor's order
     * @param  string|null  $language  a site language's tag; null for the default (I-3360)
     * @param  string|null  $via  the plugin code that wrote this, or null for a person
     */
    public function __construct(
        public string $type,
        public string $title,
        public array $document,
        public array $fields,
        public ?string $actorId,
        public array $terms = [],
        public ?string $language = null,
        public ?string $via = null,
    ) {}

    public function actorId(): ?string
    {
        return $this->actorId;
    }
}
