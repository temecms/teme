<?php

/**
 * The outside pictures a document holds: Image cards at the old site's
 * address, and featured images held the same way, which only an import
 * writes while it brings their files. A person's save may keep one but
 * never add one.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

use App\Domain\Cards\Types\ImageCard;
use Illuminate\Validation\ValidationException;

final class OutsidePictures
{
    /** Why a person's save may not add an outside picture. */
    private const string REFUSED = 'An image from the old site stays only as the import left it: choose another from the library.';

    /**
     * Each outside picture's address in the document, once each.
     *
     * @param  array<mixed>  $document
     *
     * @return list<string>
     */
    public static function sources(array $document): array
    {
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (! is_array($node)) {
                return;
            }

            if (($node['type'] ?? null) === 'image' && is_array($node['attrs'] ?? null)) {
                $source = ImageCard::source($node['attrs']);

                if ($source !== null) {
                    $found[$source] = true;
                }
            }

            foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
                $walk($child);
            }
        };
        $walk($document);

        return array_keys($found);
    }

    /**
     * Returns when no field holds an outside picture, a featured image at the
     * old site's address, that the same field didn't hold before; throws a
     * ValidationException naming the field otherwise.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $kept  the fields saved before, or [] for a new item
     */
    public static function refuseAddedFields(array $fields, array $kept): void
    {
        foreach ($fields as $key => $value) {
            $source = is_array($value) && ($value['id'] ?? null) === null ? ($value['source'] ?? null) : null;
            $before = $kept[$key] ?? null;

            if ($source !== null && (! is_array($before) || ($before['source'] ?? null) !== $source)) {
                throw ValidationException::withMessages(["fields.{$key}" => self::REFUSED]);
            }
        }
    }

    /**
     * Returns when the document holds no outside picture the kept document
     * didn't; throws a ValidationException naming the document otherwise.
     *
     * @param  array<mixed>  $document
     * @param  array<mixed>  $kept  the document saved before, or [] for a new item
     */
    public static function refuseAdded(array $document, array $kept): void
    {
        if (array_diff(self::sources($document), self::sources($kept)) !== []) {
            throw ValidationException::withMessages(['document' => self::REFUSED]);
        }
    }
}
