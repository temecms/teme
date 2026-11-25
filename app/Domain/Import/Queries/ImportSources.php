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

    /**
     * The hosts an import may leave pictures at while it copies them: each
     * source's own host and the other hosts it names, lower-cased, without
     * repeats. The admin's img-src allows them (an outside picture in the editor).
     *
     * @return list<string>
     */
    public function pictureHosts(): array
    {
        $hosts = [];

        foreach (ImportSource::query()->get(['address', 'other_hosts']) as $source) {
            $own = parse_url((string) $source->address, PHP_URL_HOST);
            $names = [...(is_string($own) ? [$own] : []), ...array_filter((array) ($source->other_hosts ?? []), is_string(...))];

            foreach ($names as $name) {
                $name = strtolower(trim($name));

                // A bare host, never anything that could end the source or the policy.
                if (preg_match('/^[a-z0-9.-]+(:[0-9]{1,5})?$/D', $name) === 1) {
                    $hosts[$name] = true;
                }
            }
        }

        $list = array_keys($hosts);
        sort($list);

        return $list;
    }
}
