<?php

/**
 * What a content type's items are, as structured data says it (I-709): the
 * kind of page each is drawn on, its main entity, if any, and the main
 * entities an item may choose instead. Declared in the type's registry
 * entry, and checked when it is made.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\StructuredData;

use InvalidArgumentException;

final readonly class TypeSchema
{
    /** The kinds of page an item may be drawn on (I-709). */
    public const array PAGE_TYPES = ['WebPage', 'AboutPage', 'ContactPage', 'CollectionPage'];

    /** The article types Google documents (I-709), which core describes as articles. */
    public const array ARTICLES = ['Article', 'NewsArticle', 'BlogPosting'];

    private const string TYPE_NAME = '/^[A-Z][A-Za-z]*$/';

    private const string PROPERTY_NAME = '/^[a-z][A-Za-z]*$/';

    private const string FIELD_KEY = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /**
     * @param  list<string>  $mainEntities  the main entities an item of the type may choose
     * @param  array<string, list<string>>  $properties  a schema.org property => the field keys it is read from, in order, the first with a value winning (I-709)
     */
    public function __construct(
        public string $pageType = 'WebPage',
        public ?string $mainEntity = null,
        public array $mainEntities = [],
        public array $properties = [],
    ) {
        if (! in_array($pageType, self::PAGE_TYPES, true)) {
            throw new InvalidArgumentException("A type's page type must be WebPage, AboutPage, ContactPage or CollectionPage, not [{$pageType}].");
        }

        foreach ($mainEntities as $name) {
            if (preg_match(self::TYPE_NAME, $name) !== 1) {
                throw new InvalidArgumentException("[{$name}] isn't a schema.org type's name: a capital letter, then letters.");
            }
        }

        if ($mainEntity !== null && ! in_array($mainEntity, $mainEntities, true)) {
            throw new InvalidArgumentException("A type's main entity [{$mainEntity}] must be one of those its items may choose.");
        }

        foreach ($properties as $property => $keys) {
            if (preg_match(self::PROPERTY_NAME, (string) $property) !== 1) {
                throw new InvalidArgumentException("[{$property}] isn't a schema.org property's name: a small letter, then letters.");
            }

            if ($keys === []) {
                throw new InvalidArgumentException("A type's property map gives [{$property}] no field keys.");
            }

            foreach ($keys as $key) {
                if (preg_match(self::FIELD_KEY, $key) !== 1) {
                    throw new InvalidArgumentException("A type's property map names [{$key}] for [{$property}], which isn't a field key.");
                }
            }
        }
    }
}
