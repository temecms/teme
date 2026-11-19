<?php

/**
 * Whose entry wins when two give the same key (I-228): a plugin's, then the
 * site's own, then core's default. Lower wins.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Site\Document;

use UnexpectedValueException;

enum Precedence: int
{
    case Plugin = 1;
    case Site = 2;
    case Core = 3;

    /**
     * Contributors in the order their entries are read: by precedence, then
     * in the order they were registered, which usort keeps, being stable.
     *
     * @template T of HeadContributor|PlacementContributor|GraphFilter
     *
     * @param  iterable<mixed>  $contributors
     * @param  class-string<T>  $kind
     *
     * @return list<T>
     */
    public static function order(iterable $contributors, string $kind): array
    {
        $ordered = [];

        foreach ($contributors as $contributor) {
            if (! $contributor instanceof $kind) {
                throw new UnexpectedValueException('Registered as a '.class_basename($kind).', but '.get_debug_type($contributor).' isn\'t one.');
            }

            $ordered[] = $contributor;
        }

        usort($ordered, fn (HeadContributor|PlacementContributor|GraphFilter $one, HeadContributor|PlacementContributor|GraphFilter $other): int => $one->precedence()->value <=> $other->precedence()->value);

        return $ordered;
    }
}
