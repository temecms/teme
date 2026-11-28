<?php

/**
 * Finds the import readers the enabled plugins offer: by a stored key, or by
 * the extension of a file someone uploads.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Readers;

use App\Domain\Plugins\Seo\SeoPlugins;
use RuntimeException;
use Teme\Contracts\Import\HasImportReader;
use Teme\Contracts\Import\ImportReader;
use Teme\Contracts\Plugins\Capability;

/**
 * The readers of the plugins that are enabled, running and granted
 * import.read. A reader whose plugin has been turned off is not offered, so
 * a source or file it held is left without a reader, not read by a stale one.
 *
 * @since 1.8.0
 */
final class ImportReaders
{
    public function __construct(private readonly SeoPlugins $plugins) {}

    /**
     * Every reader on offer, in the order the plugins were enabled.
     *
     * @return list<ImportReaderOffer>
     *
     * @since 1.8.0
     */
    public function all(): array
    {
        $offers = [];

        foreach ($this->plugins->all() as $plugin) {
            if (! $plugin->grants(Capability::ImportRead->value) || ! $plugin->running instanceof HasImportReader) {
                continue;
            }

            foreach (ImportReaderDeclaration::of($plugin->manifest) as $declared) {
                $offers[] = new ImportReaderOffer($declared['id'], $declared['label'], $declared['fileTypes'], $plugin);
            }
        }

        return $offers;
    }

    /**
     * The offer stored under a key, or null when its plugin is off or the key names no reader.
     *
     * @since 1.8.0
     */
    public function find(string $key): ?ImportReaderOffer
    {
        foreach ($this->all() as $offer) {
            if ($offer->key() === $key) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * The first offer whose file types include the file name's extension, or null for none.
     *
     * @since 1.8.0
     */
    public function forFileName(string $name): ?ImportReaderOffer
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        foreach ($this->all() as $offer) {
            if ($extension !== '' && in_array($extension, $offer->fileTypes, true)) {
                return $offer;
            }
        }

        return null;
    }

    /**
     * The reader the offer's plugin hands over for its ID.
     *
     * @since 1.8.0
     */
    public function reader(ImportReaderOffer $offer): ImportReader
    {
        $running = $offer->plugin->running;

        if (! $running instanceof HasImportReader) {
            throw new RuntimeException('The plugin no longer hands over its import readers.');
        }

        return $running->importReader($offer->readerId);
    }
}
