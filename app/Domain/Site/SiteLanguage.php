<?php

/**
 * The language the site is written in (I-435): its canonical BCP 47 code,
 * the text-search configuration that stems its words, and the one question
 * everything asks, what language one item is in: its own, from its row,
 * where a null language is the site's (I-3360).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site;

use App\Domain\Contents\Models\Content;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

final class SiteLanguage
{
    /** The language of a site that hasn't chosen one. */
    public const string DEFAULT = 'en';

    /**
     * Each item's stored language, by ID, as this request has read it, so an
     * article's parts ask its row once (I-3360).
     *
     * @var array<string, string|null>
     */
    private array $known = [];

    public function __construct(private readonly SettingsReader $settings) {}

    /**
     * The site language's BCP 47 code, such as "en" or "pt-BR".
     */
    public function tag(): string
    {
        $tag = $this->settings->string('general', 'language');

        return array_key_exists($tag, (array) config('languages')) ? $tag : self::DEFAULT;
    }

    /**
     * The PostgreSQL text-search configuration for this language, the site's
     * by default, or `simple` for one PostgreSQL has no configuration for.
     */
    public function searchConfig(?string $tag = null): string
    {
        $language = ((array) config('languages'))[$tag ?? $this->tag()] ?? null;

        return is_array($language) && is_string($language['search'] ?? null) ? $language['search'] : 'simple';
    }

    /**
     * The language one item is written in: from the model's attributes when
     * it holds its language, else one read of its row by ID, remembered for
     * the request.
     */
    public function forContent(Content|string $content): string
    {
        $id = $content instanceof Content ? (string) $content->getKey() : $content;

        if ($content instanceof Content && array_key_exists('language', $content->getAttributes())) {
            $language = $content->getAttributes()['language'];
            $this->known[$id] = is_string($language) ? $language : null;
        } elseif (! array_key_exists($id, $this->known)) {
            $language = DB::table('contents')->where('id', $id)->value('language');
            $this->known[$id] = is_string($language) ? $language : null;
        }

        return $this->of($this->known[$id]);
    }

    /**
     * The tag a row's stored language stands for: null is the site's.
     */
    public function of(?string $stored): string
    {
        return $stored ?? $this->tag();
    }

    /**
     * This language's name, the site's by default, such as "French".
     */
    public function label(?string $tag = null): string
    {
        $language = ((array) config('languages'))[$tag ?? $this->tag()] ?? null;

        return is_array($language) && is_string($language['label'] ?? null) ? $language['label'] : (string) ($tag ?? $this->tag());
    }
}
