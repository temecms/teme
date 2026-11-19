<?php

/**
 * Another item as the API names it, in an entry field (D6): its ID, type,
 * language, title and address, given only while it is live and public.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Api\Shapes;

use App\Domain\Addresses\Addresses;

final class ItemLinkShape
{
    /**
     * @param  string  $language  the language it is written in (I-3360)
     * @param  string  $path  its current address as `paths` stores it ('' for the home page)
     *
     * @return array{id: string, type: string, language: string, title: string, path: string, url: string}
     */
    public static function of(string $id, string $type, string $language, string $title, string $path): array
    {
        $relative = Addresses::toUrl($path, $type);

        return ['id' => $id, 'type' => $type, 'language' => $language, 'title' => $title, 'path' => $relative, 'url' => rtrim(url('/'), '/').$relative];
    }
}
