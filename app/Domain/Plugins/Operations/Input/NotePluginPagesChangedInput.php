<?php

/**
 * What NotePluginPagesChanged needs: the plugin that changed what its pages
 * show, and the content items it names, or none for the whole site. A job
 * acts for nobody, so there is no actor.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Operations\Input;

use Teme\Contracts\Operations\OperationInput;

final readonly class NotePluginPagesChangedInput implements OperationInput
{
    /**
     * @param  string  $name  the plugin's name, as the registry keys it
     * @param  list<string>|null  $contentIds  the content items whose pages changed; null for the whole site
     */
    public function __construct(
        public string $name,
        public ?array $contentIds = null,
    ) {}

    public function actorId(): ?string
    {
        return null;
    }
}
