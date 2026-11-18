<?php

/**
 * The links plugins add to the Tools page, filled as plugins start at boot
 * and held in memory only, each with the plugin that added it (I-3353).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Contributions;

use App\Domain\Plugins\ManifestText;

final class ToolLinks
{
    /** @var list<ToolLink> */
    private array $links = [];

    public function add(ToolLink $link): void
    {
        $this->links[] = $link;
    }

    /**
     * Returns every link plugins added, in the order they added them, with
     * each plugin's words in the current locale from its own lang folder.
     *
     * @return list<ToolLink>
     */
    public function all(): array
    {
        $locale = app()->getLocale();

        return array_map(function (ToolLink $link) use ($locale): ToolLink {
            $words = ManifestText::for($link->code);

            return new ToolLink(
                owner: $link->owner,
                code: $link->code,
                id: $link->id,
                label: $words->text($link->label, $locale),
                description: $words->text($link->description, $locale),
                icon: $link->icon,
                to: $link->to,
                plugin: $words->text($link->plugin, $locale),
            );
        }, $this->links);
    }
}
