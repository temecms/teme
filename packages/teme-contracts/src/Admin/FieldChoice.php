<?php

/**
 * One option a plugin gives for a select field whose manifest says its
 * choices come from the plugin: the value stored and the label shown.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Admin;

/**
 * One option of a select field the plugin lists each time its form opens:
 * the value stored, at most 191 characters, and the label shown as plain
 * text, at most 120 characters.
 *
 * @since next
 */
final readonly class FieldChoice
{
    /**
     * @since next
     */
    public function __construct(
        public string $value,
        public string $label,
    ) {}
}
