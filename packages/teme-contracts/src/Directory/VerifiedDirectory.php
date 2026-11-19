<?php

/**
 * Reads Teme's plugin and theme directory as Teme verified it, for a plugin
 * granted directory.read.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Directory;

/**
 * Reads Teme's plugin and theme directory as Teme verified it, for a plugin
 * granted directory.read: the last index whose signature Teme checked and
 * kept, and a listed package's detail file, checked against the hash that
 * index gives for it. Nothing here asks a person; nothing is written.
 *
 * @since next
 */
interface VerifiedDirectory
{
    /**
     * The kept index.json, decoded: its `sequence`, `generated`, `expires`
     * and `packages`. No data while the directory is switched off on this
     * site, before any index was verified, or once the kept one has run out.
     *
     * @since next
     */
    public function index(): DirectoryAnswer;

    /**
     * A listed package's packages/<vendor>/<name>.json, decoded: its
     * README, changelog, screenshots, capabilities, links and releases. No
     * data when the index can't be read, the package isn't listed, or its
     * file doesn't match the index's hash.
     *
     * @param  string  $name  the package's Composer name, such as acme/link-suggestions
     *
     * @since next
     */
    public function package(string $name): DirectoryAnswer;
}
