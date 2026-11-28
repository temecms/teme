<?php

/**
 * An item is about to be saved: asked before the change is written, so a
 * plugin can refuse it and say why.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Content;

use Teme\Contracts\Hooks\Gate;
use Teme\Contracts\Hooks\HookContract;

/**
 * An item is about to be saved: a new draft, a saved draft or a publish. It
 * is asked before the Operation's transaction opens, so nothing has been
 * written yet, and a listener that answers with a Veto stops the save and
 * its reason is shown to the person. A listener that fails, or takes too
 * long, lets the save through and is recorded as a fault.
 *
 * It is not asked for an autosave, an import run, an AI suggestion, or a
 * plugin's own writes. A save that runs inside another Operation's
 * transaction is asked inside that transaction.
 *
 * Listening needs the content.read capability.
 *
 * @since 1.8.0
 */
#[HookContract(capability: 'content.read')]
final readonly class BeforeSave implements Gate
{
    /**
     * @param  string|null  $itemId  the item's ID, or null for an item not yet created
     * @param  string  $type  the item's content type
     * @param  string|null  $language  the item's language, or null for the site's default
     * @param  string  $title  the title as it would be saved
     * @param  array<mixed>  $document  the document as it would be saved, as Tiptap JSON
     * @param  array<string, mixed>  $fields  the fields as they would be saved
     * @param  string|null  $actorId  the account ID of the person saving it, or null when none
     *
     * @since 1.8.0
     */
    public function __construct(
        public ?string $itemId,
        public string $type,
        public ?string $language,
        public string $title,
        public array $document,
        public array $fields,
        public ?string $actorId,
    ) {}
}
