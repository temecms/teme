<?php

/**
 * The site's address settings (I-393) as one typed value: a pattern per
 * public type, each type's archive page, a base per public taxonomy, the
 * author base, and what the home page shows (I-396). Read once per request
 * (a scoped binding).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Addresses;

use App\Domain\Settings\SettingsRegistry;
use App\Domain\Taxonomy\PrimaryCategory;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Types\TaxonomyRegistry;
use InvalidArgumentException;
use Teme\Contracts\Settings\SettingsReader;

final readonly class AddressSettings
{
    /** The home page lists the latest posts. */
    public const string FRONT_LATEST = 'latest';

    /** The home page shows one page (`front_page_id`). */
    public const string FRONT_PAGE = 'page';

    /**
     * @param  array<string, string>  $patterns  public type key => pattern
     * @param  array<string, string>  $bases  public taxonomy key => base, possibly ''
     * @param  array<string, string>  $archives  type key => its archive page's address, one word (I-3304)
     */
    public function __construct(
        public array $patterns,
        public array $bases,
        public string $authorBase,
        public string $frontShows,
        public string $frontPageId,
        public string $postsPageId,
        public array $archives = [],
    ) {}

    /**
     * The settings in force for this request.
     */
    public static function current(): self
    {
        return app(self::class);
    }

    /**
     * Reads the `addresses` group: one pattern per public type and one base
     * per public taxonomy, as the registries list them now.
     */
    public static function read(SettingsReader $settings, ContentTypeRegistry $types, TaxonomyRegistry $taxonomies): self
    {
        // Only what the group declares: the group follows the registries (I-3296),
        // so a type with a pattern has its setting.
        $declared = app(SettingsRegistry::class)->find('addresses');
        $patterns = [];

        foreach ($types->all() as $type) {
            if ($type->public && $declared?->setting('pattern_'.$type->key) !== null) {
                $patterns[$type->key] = $settings->string('addresses', 'pattern_'.$type->key);
            }
        }

        $bases = [];

        foreach ($taxonomies->all() as $taxonomy) {
            if ($taxonomy->public && $declared?->setting('base_'.$taxonomy->key) !== null) {
                $bases[$taxonomy->key] = $settings->string('addresses', 'base_'.$taxonomy->key);
            }
        }

        $archives = [];

        foreach (TypeArchives::types() as $key) {
            if ($declared?->setting('archive_'.$key) !== null) {
                $archives[$key] = $settings->string('addresses', 'archive_'.$key);
            }
        }

        return new self(
            $patterns,
            $bases,
            $settings->string('addresses', 'author_base'),
            $settings->string('addresses', 'front_shows'),
            $settings->string('addresses', 'front_page_id'),
            $settings->string('addresses', 'posts_page_id'),
            $archives,
        );
    }

    /**
     * The type's pattern, parsed. Throws for a type that has none, which is
     * one that isn't public.
     */
    public function pattern(string $type): AddressPattern
    {
        $pattern = $this->patterns[$type] ?? throw new InvalidArgumentException("The type [{$type}] has no address pattern.");

        return AddressPattern::parse($pattern);
    }

    /**
     * Whether the type has a public address at all.
     */
    public function hasPattern(string $type): bool
    {
        return array_key_exists($type, $this->patterns);
    }

    /**
     * Whether any public type's pattern uses this token, such as `category`:
     * then a change to what the token names moves items' addresses.
     */
    public function anyPatternUses(string $token): bool
    {
        foreach (array_keys($this->patterns) as $type) {
            if (in_array($token, $this->pattern($type)->tokens(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the type's address holds its primary category: its pattern
     * uses {category} and its type has a hierarchical taxonomy to take it
     * from (I-869).
     */
    public function followsCategory(string $type): bool
    {
        return $this->hasPattern($type)
            && in_array(AddressPattern::CATEGORY, $this->pattern($type)->tokens(), true)
            && PrimaryCategory::taxonomyFor($type) !== null;
    }

    /**
     * Whether a change to this taxonomy's terms moves items' addresses: some
     * public type's address follows its category from it.
     */
    public function termsMoveItems(string $taxonomy): bool
    {
        foreach (array_keys($this->patterns) as $type) {
            if ($this->followsCategory($type) && PrimaryCategory::taxonomyFor($type) === $taxonomy) {
                return true;
            }
        }

        return false;
    }

    /**
     * The taxonomy's base, possibly '' (its terms at the root).
     */
    public function base(string $taxonomy): string
    {
        return $this->bases[$taxonomy] ?? throw new InvalidArgumentException("The taxonomy [{$taxonomy}] has no address base.");
    }

    /**
     * Whether the taxonomy has a public address at all.
     */
    public function hasBase(string $taxonomy): bool
    {
        return array_key_exists($taxonomy, $this->bases);
    }

    /**
     * Whether this type has an archive page.
     */
    public function hasArchive(string $type): bool
    {
        return array_key_exists($type, $this->archives);
    }

    /**
     * The same settings with some values replaced, for checking a change
     * before it is saved.
     *
     * @param  array<string, string>  $patterns
     * @param  array<string, string>  $bases
     * @param  array<string, string>  $archives
     */
    public function with(array $patterns, array $bases, string $authorBase, string $frontShows, string $frontPageId, string $postsPageId, array $archives = []): self
    {
        return new self(array_replace($this->patterns, $patterns), array_replace($this->bases, $bases), $authorBase, $frontShows, $frontPageId, $postsPageId, array_replace($this->archives, $archives));
    }
}
