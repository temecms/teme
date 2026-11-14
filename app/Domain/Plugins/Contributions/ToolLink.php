<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

/**
 * One link a plugin adds to the Tools page (Q3: Tools only, I-3353): whose it
 * is, its id, its words and icon, and where it leads, `settings` for the
 * plugin's own settings page or an https address on another site.
 */
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
    ) {}
}
