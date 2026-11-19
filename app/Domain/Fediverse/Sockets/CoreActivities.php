<?php

/**
 * The activity types Teme takes and sends itself
 * (docs/plans/fediverse-publishing.md, D5): never given to a plugin's
 * handler, never sent through a plugin's FediverseSender.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets;

final class CoreActivities
{
    /** @var list<string> */
    public const array TYPES = ['Follow', 'Accept', 'Reject', 'Undo', 'Create', 'Update', 'Delete', 'Block', 'Move', 'Flag'];

    /**
     * Whether Teme keeps this activity type for itself.
     */
    public static function owns(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * The value with the keys core keeps put back as the original had them:
     * a kept key the original lacked is taken out.
     *
     * @param  array<string, mixed>  $changed
     * @param  array<string, mixed>  $original
     * @param  list<string>  $kept
     *
     * @return array<string, mixed>
     */
    public static function keep(array $changed, array $original, array $kept): array
    {
        foreach ($kept as $key) {
            if (array_key_exists($key, $original)) {
                $changed[$key] = $original[$key];
            } else {
                unset($changed[$key]);
            }
        }

        return $changed;
    }
}
