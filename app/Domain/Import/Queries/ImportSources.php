<?php

/**
 * Looks up the WordPress sites Teme keeps for import.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Queries;

use App\Domain\Import\Models\ImportSource;

final class ImportSources
{
    /**
     * The source with this ID, or null.
     */
    public function find(string $id): ?ImportSource
    {
        return ImportSource::query()->find($id);
    }

    /**
     * The source at this address, read as ConnectImportSource stores it
     * (https:// added when missing, scheme and host lower-cased, no trailing
     * slash), or null.
     */
    public function forAddress(string $address): ?ImportSource
    {
        $address = trim($address);
        $address = preg_match('#^[a-z][a-z0-9+.-]*://#i', $address) === 1 ? $address : 'https://'.$address;
        $parts = parse_url($address);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $normal = strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').rtrim($parts['path'] ?? '', '/');

        return ImportSource::query()->where('address', $normal)->first();
    }
}
