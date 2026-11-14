<?php

declare(strict_types=1);

namespace App\Domain\Import\Source;

/**
 * What a probe of a site found: WordPress's own home address, the API root
 * that answered, and the plugin's and API's versions.
 */
final readonly class SourceProbe
{
    public function __construct(
        public string $home,
        public string $apiRoot,
        public string $pluginVersion,
        public int $api,
    ) {}
}
