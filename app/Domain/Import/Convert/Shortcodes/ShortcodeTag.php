<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Shortcodes;

/**
 * One opening or closing shortcode tag as the parser read it (I-336): its
 * name, the text between the name and the bracket, and the tag as written.
 */
final readonly class ShortcodeTag
{
    public function __construct(
        public bool $closing,
        public string $name,
        public string $attributes,
        public bool $selfClosing,
        public string $raw,
    ) {}
}
