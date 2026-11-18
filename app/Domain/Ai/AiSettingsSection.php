<?php

/**
 * One section of Settings › AI (I-315): a heading and its settings, registered
 * under AiSettings::SECTIONS from its part's own provider. Where it sits is its
 * own order, never a list in a shared file; core's sections are numbered in
 * hundreds, so another fits between two.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

use Teme\Contracts\Settings\Setting;

interface AiSettingsSection
{
    /**
     * Where the section sits on the page, lowest first; no two share one.
     */
    public function order(): int;

    /**
     * Its key, lower-case words joined by hyphens, unique among the sections.
     */
    public function key(): string;

    /**
     * Its heading, as the page shows it.
     */
    public function label(): string;

    /**
     * Its settings, in the order the page shows them; their keys are unique
     * across the whole group, since it is one row.
     *
     * @return list<Setting>
     */
    public function settings(): array;
}
