<?php

/**
 * A plugin area that supplies a record's page header: its status and meta.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Admin;

/**
 * A plugin area that supplies a record's page header: its status and meta.
 * Implemented on the same class as PluginArea. Null means title only.
 *
 * @since next
 */
interface HasRecordHeader
{
    /**
     * The header for this record, or null when the page shows only the title.
     *
     * @since next
     */
    public function header(string $record, string $id, AreaContext $context): ?RecordHeader;
}
