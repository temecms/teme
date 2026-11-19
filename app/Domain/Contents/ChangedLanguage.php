<?php

/**
 * What ChangeContentLanguage did (I-3360): the item's slug now, the slug it
 * had when that one was taken in the new language, and its new address.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

final readonly class ChangedLanguage
{
    public function __construct(
        public string $contentId,
        public string $slug,
        public ?string $previousSlug,
        public string $languageLabel,
        public ?string $address = null,
    ) {}

    /**
     * The toast: moved, and why its address changed when it had to.
     */
    public function message(): string
    {
        return $this->previousSlug === null || $this->address === null
            ? __('Moved to :language.', ['language' => $this->languageLabel])
            : __('Moved to :language. Its address is now :address, because :language already has :slug.', ['language' => $this->languageLabel, 'address' => $this->address, 'slug' => $this->previousSlug]);
    }
}
