<?php

/**
 * What an export leaves out on purpose, and why (I-2408): written into every
 * manifest and the format reference, so a reader never mistakes a gap for a
 * fault.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

final class LeftOut
{
    /** @var list<array{what: string, why: string}> */
    public const array ALL = [
        ['what' => 'Passwords, two-factor secrets and recovery codes, remember-me and reset tokens', 'why' => 'Secrets: an export is for taking your content elsewhere, and a secret that leaves the site is no longer one.'],
        ['what' => 'Settings that are secrets, such as AI providers’ keys and the backups’ machine key', 'why' => 'The same: secrets never leave the site.'],
        ['what' => 'Commenters’ IP addresses and browsers', 'why' => 'Kept on this site only for a while, to fight spam, and of no use anywhere else.'],
        ['what' => 'Comments marked as spam', 'why' => 'Junk the site caught, as WordPress’s export leaves it out too.'],
        ['what' => 'The spam checks’ verdicts on comments, and the private tokens that let commenters edit their words', 'why' => 'This site’s own checks, which mean nothing to another system.'],
        ['what' => 'Items and media in the trash, and comments on items in the trash', 'why' => 'Someone removed them, and they are waiting to be deleted for good.'],
        ['what' => 'Earlier revisions, autosaves, reviews and scheduled publishing', 'why' => 'An export carries what each item says now, and its draft; its history and its workflow belong to backups.'],
        ['what' => 'The image sizes made from each original', 'why' => 'Any system that imports the originals makes its own.'],
        ['what' => 'People’s interface preferences, sessions and security log', 'why' => 'Each person’s own use of this site, not its content.'],
        ['what' => 'Site code, the page cache, the 404 log, AI activity, backups and their keys', 'why' => 'This site’s own workings, not its content.'],
        ['what' => 'The discussion settings’ hold list and block list', 'why' => 'Moderators can type IP addresses into them, and no IP address leaves the site.'],
        ['what' => 'Nothing is picked out of an item’s or a term’s fields, or out of any other setting’s value: each is written whole', 'why' => 'None of them holds a secret or an IP address today: secrets are a setting type of their own, left out above, and no field type holds one. A field or setting that ever does will be left out by name, as the two lists are.'],
    ];
}
