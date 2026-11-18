<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\Models\PluginRecord;
use Teme\Contracts\Plugins\Plugin;

/**
 * One enabled plugin as the search sockets see it: its Composer name, code
 * and label in the admin's language, its manifest, what was agreed, and the
 * plugin itself while it runs in this process.
 */
final readonly class SeoPlugin
{
    /**
     * @param  list<string>  $granted  the capabilities agreed
     */
    public function __construct(
        public string $name,
        public string $code,
        public string $label,
        public Manifest $manifest,
        public array $granted,
        public ?Plugin $running,
        public ?PluginRecord $record = null,
    ) {}

    /** Whether it was granted this capability. */
    public function grants(string $capability): bool
    {
        return in_array($capability, $this->granted, true);
    }
}
