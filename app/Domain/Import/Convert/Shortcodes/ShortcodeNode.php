<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert\Shortcodes;

/**
 * An opening tag with what it holds, closed or not, in the parser's tree
 * (I-336).
 */
final readonly class ShortcodeNode
{
    /**
     * @param  list<string|ShortcodeTag|ShortcodeNode>  $children
     */
    public function __construct(public ShortcodeTag $open, public ?ShortcodeTag $close, public array $children) {}

    /**
     * Whether a closing tag closes it.
     */
    public function closed(): bool
    {
        return $this->close !== null;
    }

    /**
     * What it holds, as written: text as it is, a stray tag as its `raw`, a
     * shortcode as its own `raw()`.
     */
    public function content(): string
    {
        $content = '';

        foreach ($this->children as $child) {
            $content .= match (true) {
                is_string($child) => $child,
                $child instanceof ShortcodeTag => $child->raw,
                default => $child->raw(),
            };
        }

        return $content;
    }

    /**
     * The opening tag, the content and the closing tag, as written.
     */
    public function raw(): string
    {
        return $this->open->raw.$this->content().($this->close === null ? '' : $this->close->raw);
    }
}
