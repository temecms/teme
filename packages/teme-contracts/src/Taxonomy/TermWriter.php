<?php

/**
 * A plugin's door for terms of the taxonomies it owns: create, update,
 * remove and list them.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Taxonomy;

use Teme\Contracts\Content\WriteRefused;

/**
 * A plugin's door for terms of the taxonomies it owns (those its types file
 * brought), offered when it was granted terms.write. It creates, updates,
 * lists and removes terms of those taxonomies only. Core's and the site's
 * own taxonomies, and another plugin's, stay out of reach. It never makes
 * or changes a translation of a term. Each write goes through as the site.
 *
 * @since next
 */
interface TermWriter
{
    /**
     * Creates a term in one of this plugin's taxonomies, and returns it.
     *
     * @param  string  $taxonomy  the taxonomy's key, as the types file named it
     * @param  string  $name  the term's name
     * @param  string|null  $slug  its slug; null makes one from the name
     * @param  string|null  $parentId  a parent term's ID in the same taxonomy, or null
     * @param  string|null  $description  a short description, or null
     *
     * @throws WriteRefused when the taxonomy is not this plugin's, the term is
     *                      refused, or the plugin may not write terms; nothing was written
     *
     * @since next
     */
    public function create(string $taxonomy, string $name, ?string $slug = null, ?string $parentId = null, ?string $description = null): TermRef;

    /**
     * Updates a term this plugin's taxonomies hold, and returns it.
     *
     * @param  string  $termId  the term's ID
     * @param  string  $name  its new name
     * @param  string|null  $slug  its new slug; null makes one from the name
     * @param  string|null  $parentId  its new parent, or null for none
     * @param  string|null  $description  its new description, or null for none
     *
     * @throws WriteRefused when the term is not of this plugin's taxonomies, the
     *                      change is refused, or the plugin may not write terms; nothing was written
     *
     * @since next
     */
    public function update(string $termId, string $name, ?string $slug = null, ?string $parentId = null, ?string $description = null): TermRef;

    /**
     * Removes a term of this plugin's taxonomies when nothing uses it and it
     * has no children.
     *
     * @param  string  $termId  the term's ID
     *
     * @throws WriteRefused when the term is in use, has children, is not of this
     *                      plugin's taxonomies, or the plugin may not write terms; nothing was written
     *
     * @since next
     */
    public function remove(string $termId): void;

    /**
     * The terms of one of this plugin's taxonomies, oldest first.
     *
     * @return list<TermRef>
     *
     * @throws WriteRefused when the taxonomy is not this plugin's
     *
     * @since next
     */
    public function list(string $taxonomy): array;
}
