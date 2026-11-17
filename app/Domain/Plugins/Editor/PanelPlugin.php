<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Editor;

use App\Domain\Plugins\Area\PluginPermissions;
use Teme\Contracts\Editor\EditorPanel;

/**
 * One editor panel of an enabled plugin: the plugin's Composer name, code
 * and label, the panel as declared, and the plugin's panel while the
 * plugin runs in this process.
 */
final readonly class PanelPlugin
{
    public function __construct(
        public string $name,
        public string $code,
        public string $plugin,
        public DeclaredPanel $panel,
        public ?EditorPanel $data,
    ) {}

    /**
     * The tool's id in the editor's bar and in the pinned tools.
     */
    public function toolId(): string
    {
        return $this->panel->toolId($this->code);
    }

    /**
     * The permission the panel needs, or null when it names none.
     */
    public function ability(): ?string
    {
        return $this->panel->permission === null ? null : PluginPermissions::name($this->code, $this->panel->permission);
    }
}
