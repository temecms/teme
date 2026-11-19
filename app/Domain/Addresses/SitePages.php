<?php

/**
 * The pages the site's own addresses rest on while the home page shows a
 * page: that page, at `/`, and the one listing the posts. Taking either off
 * the site would leave its address answering 404, so the Operations that
 * trash, unpublish or date an item ahead refuse them (#36).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Addresses;

use Illuminate\Validation\ValidationException;

final class SitePages
{
    /**
     * Refuses, in the words the admin shows, when this item is the home page
     * or the posts page; does nothing for any other item.
     *
     * @throws ValidationException
     */
    public static function refuseTakingOff(string $contentId, string $field = 'content'): void
    {
        $settings = AddressSettings::current();

        if ($settings->frontShows !== AddressSettings::FRONT_PAGE) {
            return;
        }

        if ($settings->frontPageId !== '' && $settings->frontPageId === $contentId) {
            throw ValidationException::withMessages([$field => __('This is your home page. Choose another in Settings › Reading first.')]);
        }

        if ($settings->postsPageId !== '' && $settings->postsPageId === $contentId) {
            throw ValidationException::withMessages([$field => __('This is your posts page. Choose another in Settings › Reading first.')]);
        }
    }
}
