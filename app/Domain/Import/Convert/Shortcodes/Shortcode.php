<?php

/**
 * One shortcode as a mapping row sees it (I-349): its name, its attributes
 * as WordPress reads them, its opening tag as written, and the HTML between
 * its tags when it is closed.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Convert\Shortcodes;

final readonly class Shortcode
{
    /**
     * @param  array<int|string, string>  $attributes  named ones by their lower-case name, others by place
     * @param  string|null  $content  the raw HTML between the tags, null when not closed
     */
    public function __construct(public string $name, public array $attributes, public string $open, public ?string $content) {}

    /**
     * The shortcode a parsed node is.
     */
    public static function of(ShortcodeNode $node): self
    {
        return new self(
            $node->open->name,
            ShortcodeParser::attributes($node->open->attributes),
            $node->open->raw,
            $node->closed() ? $node->content() : null,
        );
    }

    /**
     * One named attribute, or '' when it isn't there.
     */
    public function attribute(string $name): string
    {
        return $this->attributes[strtolower($name)] ?? '';
    }

    /**
     * Whether a switch is on, as WordPress's wp_validate_boolean() reads it:
     * anything but empty, `0` and `false`, without case.
     */
    public function on(string $name): bool
    {
        $value = strtolower(trim($this->attribute($name)));

        return ! in_array($value, ['', '0', 'false'], true);
    }
}
