<?php

/**
 * What a visitor sent from a plugin's public form: the plugin it came
 * through, the item's type, title, document, fields and terms, and who sent it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class ReceiveSubmissionInput implements OperationInput
{
    /**
     * @param  string  $plugin  the code of the plugin it came through
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $fields  keyed as a revision stores them
     * @param  list<string>  $terms  term IDs
     * @param  string|null  $from  who sent it, as the visitor gave it
     */
    public function __construct(
        public string $plugin,
        public string $type,
        public string $title,
        public array $document,
        public array $fields = [],
        public array $terms = [],
        public ?string $from = null,
    ) {}

    /**
     * Null: the site itself writes a submission, never a person.
     */
    public function actorId(): ?string
    {
        return null;
    }
}
