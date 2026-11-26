<?php

/**
 * A plugin granted pages.head that adds `<meta>` tags to public pages' heads.
 *
 * @package Teme
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Head;

/**
 * A plugin granted pages.head that adds `<meta>` tags to public pages' heads.
 * Core escapes and draws them; a plugin's tag wins over core's own with the
 * same name or property, except `robots`, which is laid over core's and can
 * only make the page more private.
 *
 * @since 1.1.0
 */
interface HasHeadMeta
{
    /**
     * Its tags for this page, at most 20, answered within 100 ms; none where
     * it adds nothing.
     *
     * @return list<HeadMeta>
     *
     * @since 1.1.0
     */
    public function headMeta(HeadPage $page): array;
}
