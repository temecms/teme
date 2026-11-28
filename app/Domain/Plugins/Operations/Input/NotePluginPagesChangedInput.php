<?php

/**
 * What NotePluginPagesChanged needs: the plugin that changed what its pages
 * show, the content items it names (or none, for the door's whole-site
 * tags), and those tags. A job acts for nobody, so there is no actor.
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
     * @param  array<mixed>|null  $contentIds  the content items whose pages changed, each as its ID; null for the door's tags
     * @param  list<string>  $tags  the page-cache tags a whole-site call purges: the door's own, such as `insertions` or `plugin:{code}`
     */
    public function __construct(
        public string $name,
        public ?array $contentIds = null,
        public array $tags = [],
    ) {}

    public function actorId(): ?string
    {
        return null;
    }
}
