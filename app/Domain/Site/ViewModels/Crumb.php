<?php

/**
 * One step of a breadcrumb trail: its name, and its address, site-relative.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\ViewModels;

/**
 * One step of a breadcrumb trail: its name, and its address,
 * site-relative.
 */
final readonly class Crumb
{
    /**
     * @param  string  $name  the step's name
     * @param  string  $url  the step's address, site-relative
     */
    public function __construct(public string $name, public string $url) {}
}
