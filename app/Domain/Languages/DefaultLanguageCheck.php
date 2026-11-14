<?php

declare(strict_types=1);

namespace App\Domain\Languages;

use App\Domain\Settings\SettingsCheck;

/**
 * Keeps the site's default language while it publishes in others (Q1 A):
 * rows store null for the default, so a new default would silently move
 * every default-language row into it. Refuses, too, a new default that is
 * already one of the other languages.
 */
final readonly class DefaultLanguageCheck implements SettingsCheck
{
    public function __construct(private SiteLanguages $languages) {}

    public function group(): string
    {
        return 'general';
    }

    public function problems(array $values): array
    {
        $tag = $values['language'] ?? null;

        if (! is_string($tag) || $tag === $this->languages->defaultTag() || ! $this->languages->isMultilingual()) {
            return [];
        }

        if (in_array($tag, $this->languages->others(), true)) {
            return ['values.language' => __(':language is one of the site\'s other languages. Remove it in Settings › Languages before making it the default.', ['language' => $this->languages->label($tag)])];
        }

        return ['values.language' => __('The default language can\'t change while the site publishes in other languages. Remove them in Settings › Languages first.')];
    }
}
