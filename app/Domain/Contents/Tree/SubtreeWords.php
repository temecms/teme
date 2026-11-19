<?php

/**
 * The words for moving the pages under a page (I-3363): the save's answer,
 * a page kept at its old address, and the background move's label,
 * progress and closing.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents\Tree;

final class SubtreeWords
{
    /** The most kept pages a closing names. */
    public const int NAMED = 5;

    /**
     * Returns the save's answer when the pages under it moved in the save.
     */
    public function moved(int $count): string
    {
        return $count === 1
            ? __('Saved. The page under it moved with it; its old address sends readers on.')
            : __('Saved. The :count pages under it moved with it; their old addresses send readers on.', ['count' => $count]);
    }

    /**
     * Returns the save's answer when a background job moves them.
     */
    public function queued(int $count): string
    {
        return __('Saved. The :count pages under it are moving now; each keeps answering at its old address until it moves.', ['count' => $count]);
    }

    /**
     * Returns the line for a page that kept its old address.
     */
    public function kept(string $title, string $old, string $address, string $holder): string
    {
        return __(':title keeps its address :old, because :address is used by :holder.', ['title' => '“'.$title.'”', 'old' => $old, 'address' => $address, 'holder' => $holder]);
    }

    /**
     * Returns the background move's label in the jobs indicator.
     */
    public function label(string $title): string
    {
        return __('Moving the addresses under :title', ['title' => '“'.$title.'”']);
    }

    /**
     * Returns the background move's progress.
     */
    public function progress(int $done, int $total): string
    {
        return __(':done of :total pages', ['done' => $done, 'total' => $total]);
    }

    /**
     * Returns the background move's closing words, naming at most five kept pages.
     *
     * @param  list<string>  $keptTitles
     */
    public function finished(int $count, string $title, int $kept, array $keptTitles): string
    {
        $done = $count === 1
            ? __('Moved the addresses of the page under :title', ['title' => '“'.$title.'”'])
            : __('Moved the addresses of :count pages under :title', ['count' => $count, 'title' => '“'.$title.'”']);

        if ($kept === 0) {
            return $done.'.';
        }

        $named = array_map(fn (string $each): string => '“'.$each.'”', array_slice($keptTitles, 0, self::NAMED));
        $titles = implode(', ', $named);

        if ($kept > count($named)) {
            $titles .= ' '.__('and :more more', ['more' => $kept - count($named)]);
        }

        return $done.__(", and kept :kept at their old address because the new one is someone else's: :titles.", ['kept' => $kept, 'titles' => $titles]);
    }

    /**
     * Returns the failure when the page the move was under no longer exists.
     */
    public function rootGone(): string
    {
        return __('The page these were under no longer exists, so nothing more moved.');
    }

    /**
     * Returns the failure when the move's person may no longer change the page.
     */
    public function notPermitted(string $name, string $title): string
    {
        return __(':name may no longer change :title, so the pages under it stopped moving. Someone who may can retry.', ['name' => $name, 'title' => '“'.$title.'”']);
    }
}
