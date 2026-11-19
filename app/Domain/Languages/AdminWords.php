<?php

/**
 * The admin's words: which language a person's admin is in, and that
 * language's catalogue, the one lang/{locale}.json list that PHP's __() and
 * the React screens both read, keyed by the English text.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Languages;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Translation\Translator;

final class AdminWords
{
    private const string ENGLISH = 'en';

    public function __construct(private readonly Translator $translator) {}

    /**
     * The locale this person's admin is in: English, the one admin language so far.
     */
    public function locale(?Authenticatable $user): string
    {
        // Later (I-162): a person's admin language, from their preferences, once a second admin language ships.
        return self::ENGLISH;
    }

    /**
     * A locale's catalogue as the browser receives it: its lines keyed by the
     * English text (for English, only lang/en.json's rewordings) and a version
     * that changes whenever those lines do.
     *
     * @return array{locale: string, messages: array<string, string>, version: string}
     */
    public function catalogue(string $locale): array
    {
        /** @var array<string, string> $messages */
        $messages = $this->translator->getLoader()->load($locale, '*', '*');

        return [
            'locale' => $locale,
            'messages' => $messages,
            'version' => substr(hash('xxh128', $locale."\n".json_encode($messages, JSON_THROW_ON_ERROR)), 0, 12),
        ];
    }
}
