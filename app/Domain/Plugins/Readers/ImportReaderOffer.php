<?php

/**
 * One import reader that an enabled plugin offers to the import engine: its
 * kind key, its label, its file types and the plugin that hands it over.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Readers;

use App\Domain\Plugins\Seo\SeoPlugin;

/**
 * A reader an enabled plugin declares and was granted import.read for. Its
 * key is what an import source and an upload store, `reader:` and the
 * plugin's code and the reader's ID.
 *
 * @since next
 */
final readonly class ImportReaderOffer
{
    /** The prefix of a reader's key, which tells it from WordPress's `wordpress` kind. */
    public const string PREFIX = 'reader:';

    /**
     * @param  list<string>  $fileTypes  the extensions it reads, without the dot
     *
     * @since next
     */
    public function __construct(
        public string $readerId,
        public string $label,
        public array $fileTypes,
        public SeoPlugin $plugin,
    ) {}

    /**
     * The key this reader is stored under: `reader:` and the plugin's code and the reader's ID.
     *
     * @since next
     */
    public function key(): string
    {
        return self::PREFIX.$this->plugin->code.'/'.$this->readerId;
    }
}
