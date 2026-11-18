<?php

declare(strict_types=1);

namespace App\Domain\Cards;

/**
 * A card whose own headings take their level from where it stands in the
 * page's outline, one below the last heading before it, so an FAQ under a
 * level-2 heading asks its questions at level 3.
 */
interface OutlinedCard
{
    /**
     * Its HTML with its headings at $level, from 2 to 6.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function renderInOutline(array $attrs, RenderContext $context, int $level): string;
}
