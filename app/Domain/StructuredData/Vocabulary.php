<?php

declare(strict_types=1);

namespace App\Domain\StructuredData;

/**
 * The schema.org types and properties Teme's graph may use (I-706): a list
 * of names, not a copy of the vocabulary, checked against schema.org's
 * release file whenever either changes (teme:structured-data:check). A pack
 * registers its own; a test fails if a page core draws uses a term off it.
 */
final class Vocabulary
{
    /** The schema.org release the list was last checked against. */
    public const string VERSION = '30.1';

    private const array TYPES = [
        'AboutPage', 'AccountingService', 'Article', 'BlogPosting', 'BreadcrumbList', 'CollectionPage', 'ContactPage',
        'Corporation', 'EducationalOrganization', 'GeoCoordinates', 'GovernmentOrganization', 'ImageObject', 'LegalService',
        'ListItem', 'LocalBusiness', 'MedicalBusiness', 'NGO', 'NewsArticle', 'NewsMediaOrganization',
        'OpeningHoursSpecification', 'Organization', 'Person', 'PostalAddress', 'ProfilePage', 'Restaurant',
        'SearchResultsPage', 'Store', 'VideoObject', 'WebPage', 'WebSite',
    ];

    private const array PROPERTIES = [
        'address', 'addressCountry', 'addressLocality', 'addressRegion', 'alternateName', 'articleSection', 'author',
        'breadcrumb', 'caption', 'closes', 'contentUrl', 'creditText', 'dateModified', 'datePublished', 'dayOfWeek',
        'description', 'geo', 'headline', 'height', 'image', 'inLanguage', 'isPartOf', 'item', 'itemListElement',
        'keywords', 'latitude', 'logo', 'longitude', 'mainEntity', 'mainEntityOfPage', 'name', 'openingHoursSpecification',
        'opens', 'parentOrganization', 'position', 'postalCode', 'priceRange', 'primaryImageOfPage', 'publisher', 'sameAs',
        'streetAddress', 'telephone', 'thumbnailUrl', 'uploadDate', 'url', 'width', 'wordCount',
    ];

    /** @var list<string> */
    private array $types = self::TYPES;

    /** @var list<string> */
    private array $properties = self::PROPERTIES;

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * @return list<string>
     */
    public function properties(): array
    {
        return $this->properties;
    }

    /** Whether the term is on the list, as a type or a property. */
    public function has(string $term): bool
    {
        return in_array($term, $this->types, true) || in_array($term, $this->properties, true);
    }

    /**
     * Adds a pack's terms to the list.
     *
     * @param  list<string>  $types
     * @param  list<string>  $properties
     */
    public function register(array $types, array $properties): void
    {
        $this->types = array_values(array_unique([...$this->types, ...$types]));
        $this->properties = array_values(array_unique([...$this->properties, ...$properties]));
    }

    /**
     * Every term the graph uses that isn't on the list, each once, in the
     * order met: a node's types before its properties, at any depth.
     *
     * @param  array<array-key, mixed>  $graph
     *
     * @return list<string>
     */
    public function unknown(array $graph): array
    {
        $unknown = [];
        $this->walk($graph, $unknown);

        return array_values(array_unique($unknown));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $unknown
     */
    private function walk(array $value, array &$unknown): void
    {
        $types = $value['@type'] ?? [];

        foreach (is_array($types) ? $types : [$types] as $type) {
            if (is_string($type) && ! in_array($type, $this->types, true)) {
                $unknown[] = $type;
            }
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && ! str_starts_with($key, '@') && ! in_array($key, $this->properties, true)) {
                $unknown[] = $key;
            }

            if (is_array($item)) {
                $this->walk($item, $unknown);
            }
        }
    }
}
