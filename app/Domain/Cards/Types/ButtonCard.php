<?php

declare(strict_types=1);

namespace App\Domain\Cards\Types;

use App\Domain\Cards\CardAttribute;
use App\Domain\Cards\CardMenuEntry;
use App\Domain\Cards\CardReference;
use App\Domain\Cards\CardType;
use App\Domain\Cards\ChangeList;
use App\Domain\Cards\DocumentSchema;
use App\Domain\Cards\RenderContext;
use Illuminate\Validation\ValidationException;

/**
 * A call to action that leads either to another article, held by its ID and
 * resolved at render time (rule 9), or to an address elsewhere. Exactly one of
 * the two. A button whose article is not public renders nothing.
 */
final class ButtonCard implements CardType
{
    public function name(): string
    {
        return 'button';
    }

    public function label(): string
    {
        return 'Button';
    }

    public function description(): string
    {
        return 'A call to action that leads to another article or to an address elsewhere.';
    }

    public function since(): string
    {
        return '0.1.0';
    }

    public function attributes(): array
    {
        return [
            'id' => new CardAttribute('id', ['required', 'string', 'regex:'.DocumentSchema::NODE_ID]),
            'label' => new CardAttribute('label', ['required', 'string', 'max:80']),
            // A content ID, which is lowercase (I-418).
            'contentId' => new CardAttribute('contentId', ['nullable', 'string', 'regex:/^[0-9a-hjkmnp-tv-z]{26}$/']),
            'url' => new CardAttribute('url', ['nullable', 'url:http,https', 'max:2048']),
            'style' => new CardAttribute('style', ['in:primary,secondary'], 'primary'),
        ];
    }

    public function menu(): CardMenuEntry
    {
        return new CardMenuEntry('Button', 'Cards', ['button', 'call to action', 'cta', 'link'], 'button');
    }

    public function renderHtml(array $attrs, RenderContext $context): string
    {
        $contentId = $attrs['contentId'] ?? null;
        $href = is_string($contentId) && $contentId !== ''
            ? $context->links->url($contentId)
            : (string) ($attrs['url'] ?? '');

        if ($href === null || $href === '') {
            return '';
        }

        return sprintf(
            '<a class="%s" href="%s">%s</a>',
            e('card-button card-button--'.($attrs['style'] ?? 'primary')),
            e($href),
            e((string) ($attrs['label'] ?? '')),
        );
    }

    public function plainText(array $attrs): string
    {
        return trim((string) ($attrs['label'] ?? ''));
    }

    public function references(array $attrs): array
    {
        $contentId = $attrs['contentId'] ?? null;

        return is_string($contentId) && $contentId !== '' ? [CardReference::content($contentId)] : [];
    }

    /**
     * Exactly one target: an article or an address, never both or neither.
     */
    public function validate(array $attrs): void
    {
        $hasContent = is_string($attrs['contentId'] ?? null) && $attrs['contentId'] !== '';
        $hasUrl = is_string($attrs['url'] ?? null) && $attrs['url'] !== '';

        if ($hasContent === $hasUrl) {
            throw ValidationException::withMessages([
                'contentId' => 'A button leads to an item on this site or to an address: choose one.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function describeChange(array $before, array $after): string
    {
        $parts = [];

        if (ChangeList::differs($before, $after, 'label')) {
            $parts[] = 'label changed';
        }

        if (ChangeList::differs($before, $after, 'contentId') || ChangeList::differs($before, $after, 'url')) {
            $parts[] = 'link changed';
        }

        if (ChangeList::differs($before, $after, 'style')) {
            $parts[] = 'style changed';
        }

        return ChangeList::line($this->label(), $parts);
    }
}
