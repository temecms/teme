<?php

/**
 * What a plugin's manifest declares for import readers
 * (docs/guides/developers/plugins/import-readers.md): each reader's ID,
 * label and the file types it reads, how they are checked, and what a
 * starting plugin must hand over for them.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Readers;

use App\Domain\Plugins\Manifest;
use Teme\Contracts\Import\HasImportReader;
use Teme\Contracts\Plugins\Capability;

/**
 * The import readers a manifest declares, read the way the image service's
 * declaration reads its own: the validator refuses a malformed declaration
 * first, so a reader read from a manifest is well formed.
 *
 * @since next
 */
final class ImportReaderDeclaration
{
    /** A reader's ID: a lower-case word, with hyphens. */
    public const string ID = '/^[a-z][a-z0-9-]{0,39}$/D';

    /** A file type: an extension without its dot. */
    public const string FILE_TYPE = '/^[a-z0-9]{1,8}$/D';

    /** The most readers one plugin declares. */
    public const int MOST = 5;

    /**
     * The readers the manifest declares, each as its ID, label and file types.
     *
     * @return list<array{id: string, label: string, fileTypes: list<string>}>
     *
     * @since next
     */
    public static function of(Manifest $manifest): array
    {
        $declared = $manifest->contributes()['importReaders'] ?? [];

        if (! is_array($declared) || ! array_is_list($declared)) {
            return [];
        }

        $readers = [];

        foreach ($declared as $reader) {
            if (! is_array($reader) || ! is_string($reader['id'] ?? null) || ! is_string($reader['label'] ?? null) || ! is_array($reader['fileTypes'] ?? null)) {
                continue;
            }

            $readers[] = ['id' => $reader['id'], 'label' => $reader['label'], 'fileTypes' => array_values($reader['fileTypes'])];
        }

        return $readers;
    }

    /**
     * Why the declaration can't be used, or nothing when it can. The
     * capability is checked only when the capabilities are given, as when a
     * manifest is read.
     *
     * @param  array<array-key, mixed>  $contributes
     * @param  list<string>|null  $capabilities
     *
     * @return list<string>
     *
     * @since next
     */
    public static function errors(array $contributes, ?array $capabilities): array
    {
        if (! array_key_exists('importReaders', $contributes)) {
            return [];
        }

        $declared = $contributes['importReaders'];

        if (! is_array($declared) || ! array_is_list($declared) || $declared === [] || count($declared) > self::MOST) {
            return [__('Its importReaders must be a list of one to :most readers.', ['most' => self::MOST])];
        }

        $errors = [];
        $ids = [];

        foreach ($declared as $reader) {
            if (! is_array($reader) || array_diff(array_keys($reader), ['id', 'label', 'fileTypes']) !== [] || count($reader) !== 3) {
                $errors[] = __('Each import reader must be an object of an id, a label and fileTypes.');

                continue;
            }

            if (! is_string($reader['id']) || preg_match(self::ID, $reader['id']) !== 1) {
                $errors[] = __('An import reader’s id must be a lower-case word of up to 40 characters, with hyphens.');
            } elseif (in_array($reader['id'], $ids, true)) {
                $errors[] = __('Two import readers share the id :id.', ['id' => $reader['id']]);
            } else {
                $ids[] = $reader['id'];
            }

            $label = $reader['label'];

            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 40) {
                $errors[] = __('An import reader’s label must be text of 1 to 40 characters.');
            }

            $types = $reader['fileTypes'];

            if (! is_array($types) || ! array_is_list($types) || $types === [] || count($types) > 5 || count(array_unique($types)) !== count($types)) {
                $errors[] = __('An import reader’s fileTypes must be a list of one to five different file extensions.');

                continue;
            }

            foreach ($types as $type) {
                if (! is_string($type) || preg_match(self::FILE_TYPE, $type) !== 1) {
                    $errors[] = __('An import reader’s file types are extensions without the dot, such as json.');

                    break;
                }
            }
        }

        if ($capabilities !== null && ! in_array(Capability::ImportRead->value, $capabilities, true)) {
            $errors[] = __('It adds import readers, but the plugin doesn’t ask for :capability.', ['capability' => Capability::ImportRead->value]);
        }

        return $errors;
    }

    /**
     * What a starting plugin's class must hand over for what it declares.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<string>
     *
     * @since next
     */
    public static function startErrors(array $contributes, object $plugin): array
    {
        $declared = array_key_exists('importReaders', $contributes);

        if ($declared && ! $plugin instanceof HasImportReader) {
            return [__('It declares import readers but doesn’t implement :interface.', ['interface' => HasImportReader::class])];
        }

        if (! $declared && $plugin instanceof HasImportReader) {
            return [__('It hands over import readers but declares none.')];
        }

        return [];
    }
}
