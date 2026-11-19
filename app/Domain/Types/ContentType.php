<?php

/**
 * One content type, such as post or page: its labels and admin segment, the
 * taxonomies it uses, the features it supports (which built-in fields it
 * gets), whether its items take comments, the abilities that govern it,
 * and what its items are as structured data (study Y, I-410, I-413).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Comments\Discussion;
use App\Domain\StructuredData\TypeSchema;
use InvalidArgumentException;

final readonly class ContentType
{
    /** The actions a type's abilities cover, with the global ability each defaults to. */
    public const array ACTIONS = [
        'create' => 'content.create',
        'edit.own' => 'content.edit.own',
        'edit.any' => 'content.edit.any',
        'publish' => 'content.publish',
        'delete.own' => 'content.delete.own',
        'delete.any' => 'content.delete.any',
    ];

    /**
     * @param  list<string>  $taxonomies  taxonomy keys this type may use, in the order the editor shows them
     * @param  list<string>  $supports  features: 'seo', 'excerpt', 'featured_image', 'template', 'sticky'
     * @param  array<string, string>  $abilities  action => ability name; see ContentType::ACTIONS
     * @param  Discussion|null  $comments  whether the type's items take comments, and how a new one starts: Open or Closed; null, none (I-274)
     * @param  TypeSchema|null  $schema  what search engines are told its items are (I-709); null, a web page with no main entity
     * @param  string|null  $wordpress  WordPress's name for the post type whose items an import brings into this type; null, none
     * @param  bool  $stored  declared in the admin or a file and stored in the database (I-411); false, declared by code and locked in the admin
     */
    public function __construct(
        public string $key,
        public string $singular,
        public string $plural,
        public string $segment,
        public bool $public = true,
        public bool $archive = false,
        public bool $hierarchical = false,
        public bool $single = false,
        public bool $searchable = true,
        public array $taxonomies = [],
        public array $supports = [],
        public array $abilities = [],
        public bool $listed = true,
        public string $icon = 'file',
        public ?string $pattern = null,
        public ?Discussion $comments = null,
        public ?TypeSchema $schema = null,
        public ?string $wordpress = null,
        public bool $stored = false,
    ) {}

    /**
     * The ability that governs $action for this type: its own, or the global
     * default.
     */
    public function ability(string $action): string
    {
        if (! array_key_exists($action, self::ACTIONS)) {
            throw new InvalidArgumentException("[{$action}] is not a content action; see ContentType::ACTIONS.");
        }

        return $this->abilities[$action] ?? self::ACTIONS[$action];
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->supports, true);
    }

    /**
     * The same type using these taxonomies instead.
     *
     * @param  list<string>  $taxonomies
     */
    public function withTaxonomies(array $taxonomies): self
    {
        return new self(
            key: $this->key,
            singular: $this->singular,
            plural: $this->plural,
            segment: $this->segment,
            public: $this->public,
            archive: $this->archive,
            hierarchical: $this->hierarchical,
            single: $this->single,
            searchable: $this->searchable,
            taxonomies: $taxonomies,
            supports: $this->supports,
            abilities: $this->abilities,
            listed: $this->listed,
            icon: $this->icon,
            pattern: $this->pattern,
            comments: $this->comments,
            schema: $this->schema,
            wordpress: $this->wordpress,
            stored: $this->stored,
        );
    }
}
