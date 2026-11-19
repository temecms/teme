<?php

/**
 * The language an Operation gives a new item or term, as a tag (I-3360):
 * checked against the site's languages when it validates, then again under
 * the languages' lock, read from the group's row, so a language removed in
 * between is refused rather than written.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages;

use App\Domain\Settings\Models\SettingsGroupRecord;
use Illuminate\Validation\ValidationException;

final class LanguageChoice
{
    /**
     * Refuses a tag that isn't one of the site's languages; null is the default's.
     *
     * @throws ValidationException
     */
    public static function check(?string $tag, string $key = 'language'): void
    {
        if ($tag !== null && ! app(SiteLanguages::class)->has($tag)) {
            throw self::refusal($tag, $key);
        }
    }

    /**
     * Takes the languages' lock shared and answers the tag as a row stores
     * it: null for the default language. Call it first in perform(), before
     * any other lock.
     *
     * @throws ValidationException when the language was removed since check()
     */
    public static function take(?string $tag, string $key = 'language'): ?string
    {
        $languages = app(SiteLanguages::class);

        if ($tag === null || $tag === $languages->defaultTag()) {
            return null;
        }

        LanguageLock::shared();

        // Read from the row, never the settings' cache: a removal that committed before the lock shows here.
        $overrides = (array) SettingsGroupRecord::query()->whereKey(SiteLanguages::GROUP)->first()?->overrides;

        if (! in_array($tag, (array) ($overrides['others'] ?? []), true)) {
            throw self::refusal($tag, $key);
        }

        return $languages->stored($tag);
    }

    private static function refusal(string $tag, string $key): ValidationException
    {
        return ValidationException::withMessages([$key => __(':language isn\'t one of your site\'s languages.', ['language' => app(SiteLanguages::class)->label($tag)])]);
    }
}
