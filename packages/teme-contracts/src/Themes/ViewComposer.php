<?php

/**
 * Adds data to theme views.
 *
 * @package Teme
 * @since   0.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Contracts\Themes;

/**
 * Adds data to theme views. A theme lists its composers in teme-theme.json; Teme builds each once,
 * with only Teme\Contracts services, and calls with() for every matching view. Keys a composer
 * returns must be new: a key that another composer or the page already set is an error.
 *
 * @since 0.1.0
 */
interface ViewComposer
{
    /**
     * View names this composer serves, relative to the theme, with * as a wildcard:
     * "templates.single", "partials.*".
     *
     * @return list<string>
     *
     * @since 0.1.0
     */
    public function views(): array;

    /**
     * The data to add to the view.
     *
     * @return array<string, mixed>
     *
     * @since 0.1.0
     */
    public function with(ViewContext $context): array;
}
