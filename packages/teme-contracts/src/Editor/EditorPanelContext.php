<?php

declare(strict_types=1);

namespace Teme\Contracts\Editor;

/**
 * The item the editor shows, and who is looking: the item's ID, its working
 * revision, type and language, the person's ID and language, and which of
 * the plugin's own permissions they hold.
 *
 * @since 0.9.0
 */
final readonly class EditorPanelContext
{
    /**
     * @param  string  $language  the item's language code; empty on a site with one language
     * @param  list<string>  $permissions  this plugin's own permission ids the person holds, never another plugin's
     *
     * @since 0.9.0
     */
    public function __construct(
        public string $itemId,
        public string $revisionId,
        public string $type,
        public string $language,
        public string $personId,
        public string $locale,
        public array $permissions = [],
    ) {}
}
