<?php

/**
 * A block of code in one of the languages in config/code.php, highlighted on
 * the server by scrivo/highlight.php — never Shiki, which needs Node at
 * runtime. Plain text is escaped and not highlighted.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RenderContext;
use DomainException;
use Highlight\Highlighter;

final class SnippetCard implements CardType
{
    public function name(): string
    {
        return 'snippet';
    }

    public function label(): string
    {
        return 'Code';
    }

    public function description(): string
    {
        return 'A block of code, highlighted for its language, with an optional caption.';
    }

    public function since(): string
    {
        return '0.1.0';
    }

    public function attributes(): array
    {
        $languages = array_keys((array) config('code.languages', ['plain' => 'Plain text']));

        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            'language' => new CardAttribute('language', ['in:'.implode(',', $languages)], 'plain'),
            'code' => new CardAttribute('code', ['required', 'string', 'max:100000']),
            'caption' => new CardAttribute('caption', ['nullable', 'string', 'max:2000']),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Code', 'Cards', ['code', 'snippet', 'program', 'syntax'], 'code');
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        $language = (string) ($attrs['language'] ?? 'plain');
        $code = (string) ($attrs['code'] ?? '');
        $caption = trim((string) ($attrs['caption'] ?? ''));

        return sprintf(
            '<figure class="card-snippet"><pre><code class="%s">%s</code></pre>%s</figure>',
            e('language-'.$language),
            $this->highlight($language, $code),
            $caption === '' ? '' : '<figcaption>'.e($caption).'</figcaption>',
        );
    }

    /**
     * Code is not prose, and indexing it drowns the search index, so only the
     * caption counts.
     */
    public function plainText(array $attrs): string
    {
        return trim((string) ($attrs['caption'] ?? ''));
    }

    public function references(array $attrs): array
    {
        return [];
    }

    public function validate(array $attrs): void {}

    /**
     * The code as escaped HTML, with highlight.php's spans for a known
     * language. Plain text, or a language highlight.php cannot read, is only
     * escaped.
     */
    private function highlight(string $language, string $code): string
    {
        if ($language === 'plain') {
            return e($code);
        }

        try {
            return (string) (new Highlighter)->highlight($language, $code)->value;
        } catch (DomainException) {
            return e($code);
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $parts = [];

        foreach (['language' => 'language changed', 'code' => 'code changed', 'caption' => 'caption changed'] as $key => $words) {
            if (ChangeList::differs($before, $after, $key)) {
                $parts[] = $words;
            }
        }

        return ChangeList::line($this->label(), $parts);
    }
}
