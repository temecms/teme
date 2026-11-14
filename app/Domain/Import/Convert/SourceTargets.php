<?php

declare(strict_types=1);

namespace App\Domain\Import\Convert;

/**
 * What the import made of the old site, beyond its media, as the converter
 * asks for it (I-1702): its pages, terms and people as link
 * targets, the outside images the choices step brought in, and the files
 * attached to a post. The caller implements it: the posts phase on the
 * import map, tests with KnownTargets.
 */
interface SourceTargets
{
    /**
     * What the old site's page at this key became, the key as
     * ConversionSite::pageKey() makes it: `kind` is content, term, user or
     * media (an attachment page leads to its file); null when nothing
     * imported has it yet.
     *
     * @return array{kind: string, id: string}|null
     */
    public function page(string $key): ?array;

    /**
     * The Teme article the WordPress post or page with this ID became, or null.
     */
    public function post(int $id): ?string;

    /**
     * The Teme term the WordPress term with this term ID, in this WordPress
     * taxonomy (category, post_tag), became, or null.
     */
    public function term(string $taxonomy, int $id): ?string;

    /**
     * The Teme term the WordPress term with this slug, in this WordPress
     * taxonomy, became, or null.
     */
    public function termNamed(string $taxonomy, string $slug): ?string;

    /**
     * The Teme person the WordPress user with this ID became, or null.
     */
    public function user(int $id): ?string;

    /**
     * The Teme person the WordPress user with this nicename became, or null.
     */
    public function userNamed(string $nicename): ?string;

    /**
     * The media ID of an image from another site that the choices step
     * brought into the library, by its address as written, or null.
     */
    public function outsideImage(string $address): ?string;

    /**
     * The WordPress IDs of the files of this type (image, audio, video)
     * attached to this post, in WordPress's order.
     *
     * @return list<int>
     */
    public function attached(int $postId, string $type): array;
}
