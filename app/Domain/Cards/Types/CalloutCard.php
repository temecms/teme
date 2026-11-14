<?php

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RenderContext;

/**
 * A short passage set apart from the text around it: a note, a tip or a
 * warning.
 */
final class CalloutCard implements CardType
{
    public function name(): string
    {
        return 'callout';
    }

    public function label(): string
    {
        return 'Callout';
    }

    public function description(): string
    {
        return 'A short note, tip or warning, set apart from the text around it.';
    }

    public function since(): string
    {
        return '0.1.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            'tone' => new CardAttribute('tone', ['in:note,tip,warning'], 'note'),
            'text' => new CardAttribute('text', ['required', 'string', 'max:2000']),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Callout', 'Cards', ['callout', 'note', 'tip', 'warning', 'aside'], 'callout');
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        return sprintf(
            // A note, not an <aside>: a complementary landmark inside the page's
            // <main> fails axe's landmark rule (1.6h), and a callout is a note.
            '<div class="%s" role="note"><p>%s</p></div>',
            e('card-callout card-callout--'.($attrs['tone'] ?? 'note')),
            e((string) ($attrs['text'] ?? '')),
        );
    }

    public function plainText(array $attrs): string
    {
        return trim((string) ($attrs['text'] ?? ''));
    }

    public function references(array $attrs): array
    {
        return [];
    }

    public function validate(array $attrs): void {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $parts = [];

        foreach (['tone' => 'tone changed', 'text' => 'text changed'] as $key => $words) {
            if (ChangeList::differs($before, $after, $key)) {
                $parts[] = $words;
            }
        }

        return ChangeList::line($this->label(), $parts);
    }
}
