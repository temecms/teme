<?php

declare(strict_types=1);

namespace Teme\Contracts\Insertions;

use Teme\Contracts\Content\WriteRefused;

/**
 * Asks for one of a plugin's inline scripts to be approved, so its inserted
 * markup may keep it. A person who may change site code approves it, by its
 * exact text; a plugin never can. Offered to a plugin granted
 * content.insert or pages.insert.
 *
 * @since 1.0.0
 */
interface InlineScripts
{
    /**
     * Asks, for the person this work runs for, that this script's exact
     * text be approved; nothing when that text already waits, was approved,
     * or was refused.
     *
     * @param  string  $name  what the person approving it reads, up to 80 characters
     * @param  string  $script  the script's text, as written between its tags, up to 4,096 characters
     *
     * @throws WriteRefused when no person runs this work, or too many scripts already wait
     *
     * @since 1.0.0
     */
    public function request(string $name, string $script): void;
}
