<?php

/**
 * A plugin whose manifest declares `contributes.exports`: each export it
 * declares becomes one more NDJSON file in the site's export.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Export;

/**
 * A plugin whose manifest declares `contributes.exports`: each export it
 * declares becomes one more NDJSON file in the site's export, named
 * `plugin_{code}_{id}.ndjson`, listed in the export's manifest. The plugin
 * hands over what it already keeps; core writes the file. Never put a
 * secret in a line: an export leaves the site.
 *
 * @since next
 */
interface HasExportKinds
{
    /**
     * The plugin's exports, by declared export id.
     *
     * @return array<string, PluginExportKind>
     *
     * @since next
     */
    public function exportKinds(): array;
}
