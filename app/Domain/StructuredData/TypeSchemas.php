<?php

declare(strict_types=1);

namespace App\Domain\StructuredData;

use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Types\ContentTypeRegistry;
use InvalidArgumentException;
use Teme\Contracts\Settings\SettingsReader;

/**
 * What each type's items are as structured data says it (I-709): the
 * type's own schema entry, or a web page with no main entity for a type
 * that declares none, so its items still describe themselves.
 */
final readonly class TypeSchemas
{
    /** The article type that describes no article (I-709). */
    public const string NONE = 'none';

    public function __construct(
        private ContentTypeRegistry $types,
        private FieldRegistry $fields,
        private Vocabulary $vocabulary,
        private SettingsReader $settings,
    ) {}

    /**
     * The type's schema entry: its own, or a web page with no main entity,
     * with the site's choice for the type first (I-709). A stored choice
     * the type doesn't offer, as an import could leave, keeps the type's.
     */
    public function for(string $type): TypeSchema
    {
        $declared = ($this->types->has($type) ? $this->types->get($type)->schema : null) ?? new TypeSchema;
        $values = $this->settings->group(SearchAndSocial::KEY);
        $pageType = $values[PageAndArticleTypes::pageKey($type)] ?? null;
        $mainEntity = $values[PageAndArticleTypes::articleKey($type)] ?? null;

        return new TypeSchema(
            pageType: is_string($pageType) && in_array($pageType, TypeSchema::PAGE_TYPES, true) ? $pageType : $declared->pageType,
            mainEntity: match (true) {
                $mainEntity === self::NONE && $declared->mainEntities !== [] => null,
                is_string($mainEntity) && in_array($mainEntity, $declared->mainEntities, true) => $mainEntity,
                default => $declared->mainEntity,
            },
            mainEntities: $declared->mainEntities,
            properties: $declared->properties,
        );
    }

    /**
     * The kind of page an item of the type is drawn on: its own choice
     * while it is one of the page types, else its type's.
     *
     * @param  array<string, mixed>  $fields  the item's stored fields
     */
    public function pageType(string $type, array $fields): string
    {
        $chosen = $fields[BuiltInFields::PAGE_TYPE] ?? null;

        return is_string($chosen) && in_array($chosen, TypeSchema::PAGE_TYPES, true) ? $chosen : $this->for($type)->pageType;
    }

    /**
     * What an item of the type describes as its main entity, or null for
     * nothing: its own choice while its type offers it, `none` included,
     * else its type's.
     *
     * @param  array<string, mixed>  $fields  the item's stored fields
     */
    public function mainEntity(string $type, array $fields): ?string
    {
        $schema = $this->for($type);
        $chosen = $fields[BuiltInFields::ARTICLE_TYPE] ?? null;

        return match (true) {
            $chosen === self::NONE && $schema->mainEntities !== [] => null,
            is_string($chosen) && in_array($chosen, $schema->mainEntities, true) => $chosen,
            default => $schema->mainEntity,
        };
    }

    /**
     * Refuses a type whose entry names a term off the vocabulary, or maps a
     * property from a field the type doesn't have (I-709): a typo there
     * would otherwise fail silently, page after page.
     */
    public function verify(): void
    {
        foreach ($this->types->fromCode() as $type) {
            $schema = $type->schema;

            if ($schema === null) {
                continue;
            }

            foreach ([$schema->pageType, ...$schema->mainEntities, ...array_keys($schema->properties)] as $term) {
                if (! $this->vocabulary->has((string) $term)) {
                    throw new InvalidArgumentException("Content type [{$type->key}] names [{$term}], which isn't on the structured-data vocabulary.");
                }
            }

            $keys = array_map(fn (Field $field): string => $field->key, $this->fields->forDeclaration($type));

            foreach ($schema->properties as $property => $fieldKeys) {
                foreach ($fieldKeys as $key) {
                    if (! in_array($key, $keys, true)) {
                        throw new InvalidArgumentException("Content type [{$type->key}] maps [{$property}] from field [{$key}], which it doesn't have.");
                    }
                }
            }
        }
    }
}
