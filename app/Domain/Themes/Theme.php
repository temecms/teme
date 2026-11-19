<?php

/**
 * One theme found on disk: its code, its folder, its manifest (null when
 * teme-theme.json couldn't be read at all) and every error found in it. A
 * theme with any error is broken, listed with its errors, and never used.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Themes;

final readonly class Theme
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $code,
        public string $path,
        public ?Manifest $manifest,
        public array $errors,
    ) {}

    /**
     * Whether the site may render through this theme: it has no errors.
     */
    public function usable(): bool
    {
        return $this->manifest !== null && $this->errors === [];
    }
}
