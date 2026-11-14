<?php

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * An item protected by a password, as a reader without its key sees it
 * (I-1700): its title and core's form. Its words are core's, so no theme can
 * misstate what happened.
 */
final readonly class ProtectedView
{
    /**
     * @param  string  $title  the item's published title
     * @param  string  $singular  what the item is, lower-cased: "post", "page"
     * @param  string  $path  the address the form is drawn at, as the request had it
     * @param  string|null  $error  why the last password was turned away, or null
     */
    public function __construct(
        public string $title,
        public string $singular,
        public string $path,
        public ?string $error = null,
    ) {}

    /**
     * The line that says why the form is here.
     */
    public function lead(): string
    {
        return __('This :singular is protected by a password. Enter it to read on.', ['singular' => $this->singular]);
    }

    /**
     * The same view with the error.
     */
    public function withError(string $error): self
    {
        return new self($this->title, $this->singular, $this->path, $error);
    }
}
