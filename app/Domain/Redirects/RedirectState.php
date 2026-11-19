<?php

/**
 * What a redirect does now, computed when it is read, never stored (I-857,
 * I-858): it sends readers on, it is switched off, it waits for the page or
 * old address at its source to go, or for its item or file to come back, or
 * its item or file was deleted for good.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Redirects;

enum RedirectState: string
{
    case On = 'on';
    case Off = 'off';
    case WaitingForPage = 'waiting-for-page';
    case WaitingForOldAddress = 'waiting-for-old-address';
    case WaitingForItem = 'waiting-for-item';
    case WaitingForFile = 'waiting-for-file';
    case ItemDeleted = 'item-deleted';
    case FileDeleted = 'file-deleted';

    /**
     * The state in words, the item's type named by its singular when it waits for one.
     */
    public function label(?string $singular = null): string
    {
        return match ($this) {
            self::On => __('On'),
            self::Off => __('Off'),
            self::WaitingForPage => __('Waiting: a page answers this address'),
            self::WaitingForOldAddress => __('Waiting: this old address already sends readers on'),
            self::WaitingForItem => __('Waiting: the :singular isn’t published', ['singular' => mb_strtolower($singular ?? __('item'))]),
            self::WaitingForFile => __('Waiting: the file is in the trash or not ready yet'),
            self::ItemDeleted => __('Its item was deleted'),
            self::FileDeleted => __('Its file was deleted'),
        };
    }
}
