<?php

/**
 * What a plugin's manifest declares in `contributes.exports`: each export's
 * id and noun, how they are checked, and what the plugin's class must hand
 * over for them (docs/guides/developers/plugins/exports.md).
 *
 * @package Teme
 * @since   1.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Exports;

use App\Domain\Plugins\Manifest;
use Teme\Contracts\Export\HasExportKinds;
use Teme\Contracts\Export\PluginExportKind;
use Throwable;

final class DeclaredExports
{
    /** The most exports one plugin may declare. */
    public const int MOST = 8;

    /** An export's id: lower-case letters, digits and hyphens, starting with a letter. */
    public const string ID = '/^[a-z][a-z0-9-]{1,23}$/D';

    /** A noun, one and many: `booking|bookings`. */
    public const string NOUN = '/^[^|\p{C}]{1,40}\|[^|\p{C}]{1,40}$/Du';

    /**
     * The well-formed declarations, in declared order; the validator
     * refused the others first.
     *
     * @return list<array{id: string, noun: string}>
     */
    public static function of(Manifest $manifest): array
    {
        $declared = $manifest->contributes()['exports'] ?? null;
        $found = [];

        foreach (is_array($declared) ? $declared : [] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && is_string($entry['noun'] ?? null)
                && preg_match(self::ID, $entry['id']) === 1 && preg_match(self::NOUN, $entry['noun']) === 1) {
                $found[] = ['id' => $entry['id'], 'noun' => $entry['noun']];
            }
        }

        return $found;
    }

    /**
     * Why the declaration can't be used, or nothing when it can.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<string>
     */
    public static function errors(array $contributes): array
    {
        if (! array_key_exists('exports', $contributes)) {
            return [];
        }

        $entries = $contributes['exports'];

        if (! is_array($entries) || ! array_is_list($entries)) {
            return [__('Its exports must be a list.')];
        }

        $errors = count($entries) > self::MOST ? [__('A plugin can add at most 8 exports.')] : [];
        $ids = [];

        foreach ($entries as $entry) {
            $entry = is_array($entry) ? $entry : [];
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';

            foreach (array_keys($entry) as $key) {
                if (! in_array($key, ['id', 'noun'], true)) {
                    $errors[] = __('An export can’t have :key.', ['key' => (string) $key]);
                }
            }

            if (preg_match(self::ID, $id) !== 1) {
                $errors[] = __('An export’s id must be 2 to 24 lower-case letters, digits and hyphens, starting with a letter.');
            } elseif (in_array($id, $ids, true)) {
                $errors[] = __('Two exports can’t share the id :id.', ['id' => $id]);
            }

            $ids[] = $id;

            if (! is_string($entry['noun'] ?? null) || preg_match(self::NOUN, $entry['noun']) !== 1) {
                $errors[] = __('An export’s noun is its name for one and for many, such as “booking|bookings”.');
            }
        }

        return $errors;
    }

    /**
     * What a starting plugin's class must hand over for what it declares.
     *
     * @param  array<array-key, mixed>  $contributes
     *
     * @return list<string>
     */
    public static function startErrors(array $contributes, object $plugin): array
    {
        $exports = $contributes['exports'] ?? [];
        $declared = array_values(array_filter(array_column(array_filter(is_array($exports) ? $exports : [], is_array(...)), 'id'), is_string(...)));

        if ($declared === []) {
            return $plugin instanceof HasExportKinds ? [__('It hands over exports but declares none.')] : [];
        }

        if (! $plugin instanceof HasExportKinds) {
            return [__('It declares exports but doesn’t implement :interface.', ['interface' => HasExportKinds::class])];
        }

        try {
            $given = $plugin->exportKinds();
        } catch (Throwable) {
            return [__('Its exports couldn’t be read.')];
        }

        $errors = [];

        foreach ($declared as $id) {
            if (! (($given[$id] ?? null) instanceof PluginExportKind)) {
                $errors[] = __('It declares the export “:id” but has no PluginExportKind for it.', ['id' => $id]);
            }
        }

        foreach (array_keys($given) as $id) {
            if (! in_array($id, $declared, true)) {
                $errors[] = __('It has an export “:id”, which isn’t declared.', ['id' => (string) $id]);
            }
        }

        return $errors;
    }
}
