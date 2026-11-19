<?php

/**
 * One link a plugin adds to the Tools page (Q3: Tools only, I-3353): whose it
 * is, its id, its words and icon, where it leads, `settings` for the
 * plugin's own settings page or an https address on another site, and the
 * plugin's label, which marks the link as the plugin's.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

final readonly class ToolLink
{
    public function __construct(
        public string $owner,
        public string $code,
        public string $id,
        public string $label,
        public string $description,
        public string $icon,
        public string $to,
        public string $plugin = '',
    ) {}
}
