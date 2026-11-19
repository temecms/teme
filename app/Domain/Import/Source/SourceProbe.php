<?php

/**
 * What a probe of a site found: WordPress's own home address, the API root
 * that answered, and the plugin's and API's versions.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Source;

final readonly class SourceProbe
{
    public function __construct(
        public string $home,
        public string $apiRoot,
        public string $pluginVersion,
        public int $api,
    ) {}
}
