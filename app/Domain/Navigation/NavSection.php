<?php

/**
 * The sections of the admin's main menu, in the order they appear. The set
 * is closed: a plugin picks a section and never adds one (I-216).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Navigation;

enum NavSection: string
{
    case Home = 'home';
    case Content = 'content';
    case Site = 'site';
}
