<?php

declare(strict_types=1);

namespace App\Domain\Cards;

use App\Domain\Cards\Exceptions\InvalidDocumentException;
use Illuminate\Validation\ValidationException;

/**
 * A short piece of rich text held inside a card's attributes, such as an FAQ
 * answer or a step: a small document of paragraphs, lists, line breaks and
 * marked text, stored as Tiptap JSON like the body (rule 7) and checked,
 * drawn, read and searched by the body's own rules.
 */
final class CardProse
{
    /** The nodes it may hold: no headings, quotes, code, tables or cards. */
    public const array NODES = ['paragraph', 'bulletList', 'orderedList', 'listItem', 'hardBreak', 'text'];

    /** The most nodes one piece may hold. */
    public const int MOST_NODES = 500;

    /**
     * An empty piece, as the editor starts one.
     *
     * @return array{type: string, content: list<array{type: string}>}
     */
    public static function blank(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    }

    /**
     * Returns when $value is a piece of card prose that fits; throws a
     * ValidationException under $key otherwise.
     */
    public static function check(mixed $value, string $key, bool $required = true): void
    {
        if (! is_array($value) || ($value['type'] ?? null) !== 'doc' || ! is_array($value['content'] ?? null)) {
            throw ValidationException::withMessages([$key => __('This text isn’t in a form Teme can save.')]);
        }

        $count = 0;
        $unknown = self::unknownNode($value['content'], $count);

        if ($unknown !== null) {
            throw ValidationException::withMessages([$key => __('This text can hold paragraphs and lists only, not “:type”.', ['type' => $unknown])]);
        }

        if ($count > self::MOST_NODES) {
            throw ValidationException::withMessages([$key => __('This text is too long.')]);
        }

        try {
            app(DocumentValidator::class)->validate($value);
        } catch (InvalidDocumentException $exception) {
            throw ValidationException::withMessages([$key => $exception->getMessage()]);
        }

        if ($required && self::text($value) === '') {
            throw ValidationException::withMessages([$key => __('Write some text here.')]);
        }
    }

    /**
     * Its HTML, drawn by the body's renderer in the context of the page it
     * stands on, with nothing inserted into it.
     */
    public static function html(mixed $value, RenderContext $context): string
    {
        if (! is_array($value) || self::text($value) === '') {
            return '';
        }

        return app(DocumentRenderer::class)->html(
            ['type' => 'doc', 'content' => array_values((array) ($value['content'] ?? []))],
            new RenderContext($context->links, $context->target, $context->media, linkAttributes: $context->linkAttributes),
        );
    }

    /**
     * Its words on one line, '' when it has none.
     */
    public static function text(mixed $value): string
    {
        return is_array($value) ? PlainText::of($value) : '';
    }

    /**
     * What its links lead to by ID.
     *
     * @return list<CardReference>
     */
    public static function references(mixed $value): array
    {
        return is_array($value) ? References::of($value) : [];
    }

    /**
     * The first node type it may not hold, or null; counts every node.
     *
     * @param  array<mixed>  $content
     */
    private static function unknownNode(array $content, int &$count): ?string
    {
        foreach ($content as $node) {
            $count++;
            $type = is_array($node) ? ($node['type'] ?? null) : null;

            if (! is_string($type) || ! in_array($type, self::NODES, true)) {
                return is_string($type) ? $type : '?';
            }

            $inner = self::unknownNode((array) ($node['content'] ?? []), $count);

            if ($inner !== null) {
                return $inner;
            }
        }

        return null;
    }
}
