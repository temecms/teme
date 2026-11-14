<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

/**
 * The sections of the admin's main menu, in the order they appear. The set
 * is closed: a plugin picks a section and never adds one (I-216).
 */
enum NavSection: string
{
    case Home = 'home';
    case Content = 'content';
    case Site = 'site';
}
