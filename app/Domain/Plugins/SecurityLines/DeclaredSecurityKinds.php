<?php

/**
 * The kinds of line a plugin writes into the security log, from its manifest's
 * `contributes.securityLog`: each an id and a label, up to 10 a plugin.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\SecurityLines;

use App\Domain\Plugins\Manifest;

final class DeclaredSecurityKinds
{
    /** A kind's id within its plugin. */
    public const string ID = '/^[a-z][a-z0-9_]{1,31}$/D';

    /** At most this many kinds a plugin declares. */
    public const int MOST = 10;

    /** A kind's label, in characters. */
    public const int MOST_LABEL = 60;

    /**
     * The plugin's declared kinds by id, each with its label; none when the
     * list's shape is wrong, since the validator has already refused it.
     *
     * @return array<string, string>
     */
    public static function of(Manifest $manifest): array
    {
        $kinds = $manifest->contributes()['securityLog'] ?? null;

        if (! is_array($kinds) || ! array_is_list($kinds)) {
            return [];
        }

        $declared = [];

        foreach ($kinds as $kind) {
            if (is_array($kind) && is_string($kind['id'] ?? null) && is_string($kind['label'] ?? null)) {
                $declared[$kind['id']] = $kind['label'];
            }
        }

        return $declared;
    }

    /**
     * Returns why the declared kinds can't be used, or nothing when they can.
     *
     * @return list<string>
     */
    public static function errors(mixed $kinds): array
    {
        if (! is_array($kinds) || ! array_is_list($kinds) || $kinds === [] || count($kinds) > self::MOST) {
            return [__('A plugin declares 1 to 10 kinds of security log line.')];
        }

        $errors = [];
        $ids = [];

        foreach ($kinds as $kind) {
            $id = is_array($kind) ? ($kind['id'] ?? null) : null;
            $label = is_array($kind) ? ($kind['label'] ?? null) : null;

            if (! is_string($id) || preg_match(self::ID, $id) !== 1 || in_array($id, $ids, true)) {
                $errors[] = __('A security log line kind needs an id of 2 to 32 lower-case letters, digits and underscores, starting with a letter, and no two alike.');
            } else {
                $ids[] = $id;
            }

            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > self::MOST_LABEL) {
                $errors[] = __('A security log line kind needs a label of 1 to 60 characters.');
            }
        }

        return $errors;
    }
}
