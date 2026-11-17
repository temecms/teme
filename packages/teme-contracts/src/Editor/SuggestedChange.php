<?php

declare(strict_types=1);

namespace Teme\Contracts\Editor;

use InvalidArgumentException;

/**
 * The changes core knows how to check, show and apply in the editor: the
 * title, a scalar field, terms, an internal link on a phrase, or a whole
 * document shown as a proposal. Made only by its named constructors.
 *
 * @since 1.0.0
 */
final readonly class SuggestedChange
{
    /** A new title. @since 1.0.0 */
    public const string TITLE = 'title';

    /** A scalar field's new value. @since 1.0.0 */
    public const string FIELD = 'field';

    /** Terms to add. @since 1.0.0 */
    public const string TERMS = 'terms';

    /** An internal link on a phrase. @since 1.0.0 */
    public const string LINK = 'link';

    /** A whole document, as a proposal. @since 1.0.0 */
    public const string BODY = 'body';

    /**
     * @param  array<string, mixed>  $values  what the kind needs, as its constructor names them
     */
    private function __construct(
        public string $kind,
        public array $values,
    ) {}

    /**
     * A new title, 1 to 512 characters.
     *
     * @throws InvalidArgumentException for an empty or over-long title
     *
     * @since 1.0.0
     */
    public static function title(string $title): self
    {
        if (trim($title) === '' || mb_strlen($title) > 512) {
            throw new InvalidArgumentException('A suggested title must be 1 to 512 characters.');
        }

        return new self(self::TITLE, ['title' => $title]);
    }

    /**
     * A new value for one of the type's scalar fields, by its handle.
     *
     * @throws InvalidArgumentException for a handle that isn't one, or text over 2,000 characters
     *
     * @since 1.0.0
     */
    public static function field(string $handle, string|int|float|bool|null $value): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $handle) !== 1) {
            throw new InvalidArgumentException('A field’s handle must be lower-case letters, digits and underscores, starting with a letter.');
        }

        if (is_string($value) && mb_strlen($value) > 2_000) {
            throw new InvalidArgumentException('A suggested field value may be at most 2,000 characters.');
        }

        return new self(self::FIELD, ['handle' => $handle, 'value' => $value]);
    }

    /**
     * Terms of one taxonomy to add to the item, by slug or name, 1 to 10.
     *
     * @param  list<string>  $slugsOrNames
     *
     * @throws InvalidArgumentException for an empty taxonomy or list, or a term that is empty or over 200 characters
     *
     * @since 1.0.0
     */
    public static function terms(string $taxonomy, array $slugsOrNames): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $taxonomy) !== 1) {
            throw new InvalidArgumentException('A taxonomy’s key must be lower-case letters, digits and underscores, starting with a letter.');
        }

        if ($slugsOrNames === [] || count($slugsOrNames) > 10) {
            throw new InvalidArgumentException('Suggest 1 to 10 terms at a time.');
        }

        foreach ($slugsOrNames as $term) {
            if (trim($term) === '' || mb_strlen($term) > 200) {
                throw new InvalidArgumentException('A suggested term must be 1 to 200 characters.');
            }
        }

        return new self(self::TERMS, ['taxonomy' => $taxonomy, 'terms' => array_values(array_unique($slugsOrNames))]);
    }

    /**
     * An internal link to another item on the nth plain-text match of a
     * phrase, stored by the item's ID; the address is made when the page
     * is drawn.
     *
     * @throws InvalidArgumentException for an empty or over-long phrase, an empty ID, or an occurrence outside 1 to 50
     *
     * @since 1.0.0
     */
    public static function link(string $phrase, string $itemId, int $occurrence = 1): self
    {
        if (trim($phrase) === '' || mb_strlen($phrase) > 200) {
            throw new InvalidArgumentException('A linked phrase must be 1 to 200 characters.');
        }

        if ($itemId === '' || strlen($itemId) > 64) {
            throw new InvalidArgumentException('A link needs the ID of the item it leads to.');
        }

        if ($occurrence < 1 || $occurrence > 50) {
            throw new InvalidArgumentException('A link’s occurrence must be from 1 to 50.');
        }

        return new self(self::LINK, ['phrase' => $phrase, 'itemId' => $itemId, 'occurrence' => $occurrence]);
    }

    /**
     * A whole Tiptap document, shown beside the article as a proposal.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws InvalidArgumentException for anything but a document of type doc
     *
     * @since 1.0.0
     */
    public static function body(array $document): self
    {
        if (($document['type'] ?? null) !== 'doc' || ! is_array($document['content'] ?? null)) {
            throw new InvalidArgumentException('A suggested body must be a Tiptap document of type doc.');
        }

        return new self(self::BODY, ['document' => $document]);
    }
}
