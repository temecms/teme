<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Area;

use Teme\Contracts\Admin\PluginArea;

/**
 * An enabled plugin that declares an area: its Composer name, code and
 * label, the area as declared, and its data while it runs in this process.
 */
final readonly class AreaPlugin
{
    public function __construct(
        public string $name,
        public string $code,
        public string $label,
        public DeclaredArea $area,
        public ?PluginArea $data,
    ) {}

    /**
     * The permission the area's pages and writes need.
     */
    public function ability(): string
    {
        return PluginPermissions::name($this->code, $this->area->permission);
    }
}
