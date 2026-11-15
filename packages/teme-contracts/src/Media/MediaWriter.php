<?php

declare(strict_types=1);

namespace Teme\Contracts\Media;

use Teme\Contracts\Content\WriteRefused;

/**
 * How a plugin granted media.write adds media, including from an outside
 * address on a host the administrator agreed to.
 *
 * @since 0.9.0
 */
interface MediaWriter
{
    /**
     * Stores a file fetched from an agreed host.
     *
     * @throws WriteRefused
     *
     * @since 0.9.0
     */
    public function fromAddress(string $url, string $actingPersonId, ?string $alt = null, ?string $title = null): MediaRef;
}
