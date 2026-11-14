<?php

declare(strict_types=1);

namespace App\Domain\Ai\Tasks\Metadata;

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiInput;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiTask;
use App\Domain\Ai\Asking\Operations\ApplyAiSuggestion;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;

/**
 * The metadata task (I-1313): from an item's title, excerpt and body, an SEO
 * title, a meta description, an excerpt, a social title and a social
 * description, each proposed under its own field and written only when the
 * person uses it.
 */
final readonly class MetadataTask implements AiTask
{
    public const string KEY = 'metadata';

    /** Each proposal's field handle and the most characters it may have, in the order asked for. */
    public const array LIMITS = [
        'seo_title' => 60,
        'meta_description' => 155,
        'excerpt' => 300,
        'social_title' => 70,
        'social_description' => 200,
    ];

    /** The most of the body a model is sent, in characters. */
    public const int BODY_LIMIT = 20000;

    public const int VERSION = 1;

    public const string INSTRUCTIONS = 'You write how one article is described in search results and on social cards, and its excerpt. The material is the article title, its excerpt (which may be empty), its body (which may be cut short) and the language to write in.'
        ."\n\n".'Write five things, each in the language named, each as plain text on one line:'
        ."\n".'- seo_title: the title search engines show, at most 60 characters. Say plainly what the article is about, and leave out the site name.'
        ."\n".'- meta_description: the summary search engines may show under the title, at most 155 characters, in one or two sentences.'
        ."\n".'- excerpt: a summary shown in lists and feeds, at most 300 characters, in one or two sentences in the voice of the article.'
        ."\n".'- social_title: the title on the card when the article is shared, at most 70 characters.'
        ."\n".'- social_description: the text on that card, at most 200 characters.'
        ."\n\n".'Use only what the material says. Add no fact, name, number, date or claim that it does not contain, and promise nothing it does not deliver. Put no quotation marks around a value, and use no Markdown, HTML, emoji, hashtags or clickbait. Keep each one within its limit, counting every character.';

    /** Pinned beside the instructions: a changed prompt is a new version (I-316). */
    public const string HASH = '7fbd5b683dc421f8239d1ce373700d809f21a446bbd42ce0230634408e0865b7';

    /** What each proposal is, as the answer's shape says it. */
    private const array DESCRIPTIONS = [
        'seo_title' => 'The title search engines show.',
        'meta_description' => 'The summary search engines may show under the title.',
        'excerpt' => 'A summary shown in lists and feeds.',
        'social_title' => 'The title on the card when the article is shared.',
        'social_description' => 'The text on that card.',
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Metadata';
    }

    public function subject(): AiSubjectType
    {
        return AiSubjectType::Content;
    }

    public function capabilities(): array
    {
        return [AiCapability::Text, AiCapability::StructuredOutput];
    }

    public function prompt(): AiPrompt
    {
        return new AiPrompt(self::KEY, self::VERSION, self::INSTRUCTIONS, self::HASH);
    }

    public function timeoutSeconds(): int
    {
        return 30;
    }

    public function maxOutputTokens(): int
    {
        return 2000;
    }

    /**
     * Five strings, each with its limit, as the provider's structured output.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(): array
    {
        $properties = [];

        foreach (self::LIMITS as $handle => $limit) {
            $properties[$handle] = ['type' => 'string', 'description' => self::DESCRIPTIONS[$handle].' At most '.$limit.' characters.', 'maxLength' => $limit];
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys(self::LIMITS), 'additionalProperties' => false];
    }

    /**
     * The revision asked about, never a later one: its title, its excerpt and
     * its body's plain text, cut at a word's end. Nothing else of the item is
     * read (I-316).
     */
    public function input(AiSubject $subject): AiInput
    {
        $revision = ContentRevision::query()->where('content_id', $subject->id)->findOrFail((string) $subject->revisionId);
        $excerpt = ((array) $revision->fields)[BuiltInFields::EXCERPT] ?? null;

        return new AiInput([
            'title' => (string) $revision->title,
            'excerpt' => is_string($excerpt) ? $excerpt : '',
            'body' => self::cut((string) $revision->plain_text),
        ]);
    }

    /**
     * Each proposal checked on its own: one line of text within its field's
     * limit. One that isn't is left out and the rest are offered; none at all
     * is an answer that can't be used.
     *
     * @return array<string, string>
     */
    public function check(AiResult $result): array
    {
        $values = [];

        foreach (self::LIMITS as $handle => $limit) {
            $value = $result->data[$handle] ?? null;
            $value = is_string($value) ? trim($value) : '';

            if ($value !== '' && mb_strlen($value) <= $limit && preg_match('/[\r\n]/', $value) !== 1) {
                $values[$handle] = $value;
            }
        }

        return $values === [] ? throw AiFailure::invalidOutput() : $values;
    }

    public function appliedBy(): string
    {
        return ApplyAiSuggestion::class;
    }

    /**
     * The text, or as much of it as BODY_LIMIT allows, ending at a word's end.
     */
    private static function cut(string $text): string
    {
        if (mb_strlen($text) <= self::BODY_LIMIT) {
            return $text;
        }

        return rtrim((string) preg_replace('/\s+\S*$/u', '', mb_substr($text, 0, self::BODY_LIMIT)));
    }
}
