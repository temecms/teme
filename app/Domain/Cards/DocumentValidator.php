<?php

declare(strict_types=1);

namespace App\Domain\Cards;

use App\Domain\Cards\Exceptions\InvalidDocumentException;
use App\Domain\Regions\RegionRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Checks a whole document against the schema before it is stored: every
 * node, mark and attribute must be one the schema or a registered card
 * declares. Anything else is refused, never dropped, so nothing the browser
 * sends reaches the database unchecked.
 */
final readonly class DocumentValidator
{
    /** The keys a Tiptap node may have. */
    private const array NODE_KEYS = ['type', 'attrs', 'content', 'marks', 'text'];

    public function __construct(private CardRegistry $cards) {}

    /**
     * Returns when the document fits; throws InvalidDocumentException at the
     * first part that does not. With $allowUnfinishedCards, a card may still
     * lack what completes it (its required values, a gallery's second image,
     * a button's destination), which only an autosave allows (B-142); every
     * other rule holds, and a card always needs its id. With $rules, a
     * region's, the document must also keep to its region's cards and block
     * limit; without them, a card that belongs in regions only is refused
     * (I-3127). Every caller passes $rules by name, and fix #1's editor guard
     * passes $allowUnfinishedCards by name (I-452), so the order stays.
     *
     * @param  array<mixed>  $document
     */
    public function validate(array $document, bool $allowUnfinishedCards = false, ?RegionRules $rules = null): void
    {
        if (($document['type'] ?? null) !== 'doc' || ! is_array($document['content'] ?? null) || ! array_is_list($document['content'])) {
            throw InvalidDocumentException::at('', 'A document is a doc node with a list of content.');
        }

        if (strlen((string) json_encode($document)) > DocumentSchema::MAX_BYTES) {
            throw InvalidDocumentException::at('', 'The document is larger than '.intdiv(DocumentSchema::MAX_BYTES, 1024 * 1024).' MB.');
        }

        $count = 0;

        foreach ($document['content'] as $index => $node) {
            $this->node($node, "content.{$index}", 1, $count, $allowUnfinishedCards, $rules !== null);
        }

        $rules?->check($document);
    }

    private function node(mixed $node, string $path, int $depth, int &$count, bool $unfinished, bool $inRegion): void
    {
        if (! is_array($node)) {
            throw InvalidDocumentException::at($path, 'A node must be an object.');
        }

        if (++$count > DocumentSchema::MAX_NODES) {
            throw InvalidDocumentException::at($path, 'The document has more than '.number_format(DocumentSchema::MAX_NODES).' nodes.');
        }

        if ($depth > DocumentSchema::MAX_DEPTH) {
            throw InvalidDocumentException::at($path, 'The document nests more than '.DocumentSchema::MAX_DEPTH.' levels deep.');
        }

        $type = $node['type'] ?? null;

        if (! is_string($type) || $type === '' || $type === 'doc') {
            throw InvalidDocumentException::at("{$path}.type", 'A node needs a type.');
        }

        $unknown = array_diff(array_keys($node), self::NODE_KEYS);

        if ($unknown !== []) {
            throw InvalidDocumentException::at($path, 'A node may not carry '.implode(', ', $unknown).'.');
        }

        $attrs = $node['attrs'] ?? [];

        if (! is_array($attrs)) {
            throw InvalidDocumentException::at("{$path}.attrs", 'Attributes must be an object.');
        }

        $card = $this->cards->find($type);

        if ($card !== null) {
            if ($card instanceof RegionCard && ! $inRegion) {
                throw InvalidDocumentException::at("{$path}.type", __("This card goes in a theme's region only."));
            }

            $this->card($card, $attrs, $node, $path, $unfinished);

            return;
        }

        if (! in_array($type, DocumentSchema::NODES, true)) {
            throw InvalidDocumentException::at("{$path}.type", "Teme doesn’t know the “{$type}” block, so it can’t save it.");
        }

        $this->attributes($attrs, DocumentSchema::nodeAttributes()[$type] ?? [], "{$path}.attrs");

        if ($type === 'text') {
            if (! is_string($node['text'] ?? null) || $node['text'] === '') {
                throw InvalidDocumentException::at("{$path}.text", 'A text node needs some text.');
            }

            if (isset($node['content'])) {
                throw InvalidDocumentException::at("{$path}.content", 'A text node has no content.');
            }

            $this->marks($node['marks'] ?? [], "{$path}.marks");

            return;
        }

        if (isset($node['text']) || isset($node['marks'])) {
            throw InvalidDocumentException::at($path, 'Only a text node carries text or marks.');
        }

        $content = $node['content'] ?? [];

        if (! is_array($content) || ! array_is_list($content)) {
            throw InvalidDocumentException::at("{$path}.content", 'Content must be a list of nodes.');
        }

        foreach ($content as $index => $child) {
            $this->node($child, "{$path}.content.{$index}", $depth + 1, $count, $unfinished, $inRegion);
        }
    }

    /**
     * A card's attributes are exactly those it declares, each passing its
     * rules, and then the card's own check.
     *
     * @param  array<mixed>  $attrs
     * @param  array<mixed>  $node
     */
    private function card(CardType $card, array $attrs, array $node, string $path, bool $unfinished): void
    {
        if (isset($node['content']) || isset($node['marks']) || isset($node['text'])) {
            throw InvalidDocumentException::at($path, "A {$card->name()} card holds only attributes.");
        }

        $rules = [];

        foreach ($card->attributes() as $name => $attribute) {
            $rules[$name] = $unfinished && $name !== 'id' ? self::withoutCompleteness($attribute->rules) : $attribute->rules;
        }

        $this->attributes($attrs, $rules, "{$path}.attrs");

        // A card's own check is about completeness: a button leads somewhere.
        if ($unfinished) {
            return;
        }

        try {
            $card->validate($attrs);
        } catch (ValidationException $exception) {
            $key = (string) array_key_first($exception->errors());

            throw InvalidDocumentException::at("{$path}.attrs.{$key}", (string) ($exception->errors()[$key][0] ?? 'Invalid.'));
        }
    }

    /**
     * Every mark is one the schema knows; only a link carries attributes, and
     * its address must be allowed.
     */
    private function marks(mixed $marks, string $path): void
    {
        if (! is_array($marks) || ! array_is_list($marks)) {
            throw InvalidDocumentException::at($path, 'Marks must be a list.');
        }

        foreach ($marks as $index => $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;

            if (! is_string($type) || ! in_array($type, DocumentSchema::MARKS, true)) {
                throw InvalidDocumentException::at("{$path}.{$index}.type", 'Nothing registers a mark of type ['.(is_string($type) ? $type : '?').'].');
            }

            $unknown = array_diff(array_keys($mark), ['type', 'attrs']);

            if ($unknown !== []) {
                throw InvalidDocumentException::at("{$path}.{$index}", 'A mark may not carry '.implode(', ', $unknown).'.');
            }

            $attrs = $mark['attrs'] ?? [];

            if (! is_array($attrs)) {
                throw InvalidDocumentException::at("{$path}.{$index}.attrs", 'Attributes must be an object.');
            }

            if ($type !== 'link') {
                $this->attributes($attrs, [], "{$path}.{$index}.attrs");

                continue;
            }

            $this->attributes($attrs, DocumentSchema::linkAttributes(), "{$path}.{$index}.attrs");

            if (! DocumentSchema::allowsHref((string) $attrs['href'])) {
                throw InvalidDocumentException::at("{$path}.{$index}.attrs.href", 'A link can go to an item, a category or tag, an author or a file on this site, a page on this site (/…), a place on a page (#…), or a web, email or phone address.');
            }
        }
    }

    /**
     * An attribute's rules without the ones that ask for completeness: a
     * value may be missing, and a list may be short.
     *
     * @param  list<mixed>  $rules
     *
     * @return list<mixed>
     */
    private static function withoutCompleteness(array $rules): array
    {
        $kept = array_values(array_filter(
            $rules,
            fn (mixed $rule): bool => ! is_string($rule) || ($rule !== 'required' && ! str_starts_with($rule, 'min:')),
        ));

        return in_array('nullable', $kept, true) ? $kept : ['nullable', ...$kept];
    }

    /**
     * The attributes are only those named, and each passes its rules.
     *
     * @param  array<mixed>  $attrs
     * @param  array<string, list<mixed>>  $rules
     */
    private function attributes(array $attrs, array $rules, string $path): void
    {
        $unknown = array_diff(array_map('strval', array_keys($attrs)), array_keys($rules));

        if ($unknown !== []) {
            $name = (string) reset($unknown);

            throw InvalidDocumentException::at("{$path}.{$name}", "Teme can’t save the “{$name}” setting here.");
        }

        if ($rules === []) {
            return;
        }

        $validator = Validator::make($attrs, $rules);

        if ($validator->fails()) {
            $key = (string) array_key_first($validator->errors()->messages());

            throw InvalidDocumentException::at("{$path}.{$key}", (string) $validator->errors()->first($key));
        }
    }
}
