<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Shortcodes;

use App\Domain\Import\Convert\BlockContext;

/**
 * A row of the shortcode mapping registry (I-349): the WordPress
 * shortcodes it converts, and the Tiptap nodes each becomes. Core's rows
 * are listed in ShortcodeMappings::CORE; a plugin tags its own under
 * ShortcodeMappings::TAG.
 */
interface ShortcodeMapping
{
    /**
     * The shortcode names it converts, as WordPress registered them, in
     * lower case.
     *
     * @return list<string>
     */
    public function shortcodes(): array;

    /**
     * The nodes the shortcode becomes, in order: none to leave it out. A
     * row reports what it leaves out through the context's report.
     *
     * @return list<array<string, mixed>>
     */
    public function convert(Shortcode $shortcode, BlockContext $context): array;
}
