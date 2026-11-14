<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

/**
 * The links plugins add to the Tools page, filled as plugins start at boot
 * and held in memory only, each with the plugin that added it (I-3353).
 */
final class ToolLinks
{
    /** @var list<ToolLink> */
    private array $links = [];

    public function add(ToolLink $link): void
    {
        $this->links[] = $link;
    }

    /**
     * Returns every link plugins added, in the order they added them.
     *
     * @return list<ToolLink>
     */
    public function all(): array
    {
        return $this->links;
    }
}
