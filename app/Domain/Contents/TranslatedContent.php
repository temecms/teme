<?php

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Contents\Models\Content;

/**
 * What TranslateContent made (I-3360): the new draft, the terms it made in
 * that language, and whether a page went to the top because its parent has
 * no version in the language yet.
 */
final readonly class TranslatedContent
{
    /**
     * @param  list<string>  $madeTerms  the IDs of the terms made, parents first
     */
    public function __construct(
        public Content $content,
        public array $madeTerms,
        public bool $parentLeft = false,
        public ?string $parentTitle = null,
        public ?string $languageLabel = null,
    ) {}

    /**
     * The sentence telling a page went to the top, or null when it didn't.
     */
    public function parentLeftMessage(): ?string
    {
        return $this->parentLeft
            ? __('Its parent “:parent” has no :language version yet, so it is at the top. Choose its parent once there is one.', ['parent' => (string) $this->parentTitle, 'language' => (string) $this->languageLabel])
            : null;
    }
}
