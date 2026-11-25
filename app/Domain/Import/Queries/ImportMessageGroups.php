<?php

/**
 * Plain names and short sentences for import message codes on the report tab
 * (issue 699): each code becomes a foldable group people can scan, instead of
 * one raw line per code. Unknown codes fall back to a title from the code.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Queries;

final class ImportMessageGroups
{
    /**
     * Plain name and sentence for a code people often see. The sentence
     * explains the group; unknown codes fall back below.
     *
     * @var array<string, array{group: string, sentence: string}>
     */
    private const array GROUPS = [
        'term.shared' => ['group' => 'Existing categories reused', 'sentence' => 'Teme already had a category or tag at this address, so posts were filed under that one.'],
        'user.password-reset' => ['group' => 'Password reset required', 'sentence' => 'Teme can’t check the password WordPress stored, so these people need to reset it before they first sign in.'],
        'user.matched' => ['group' => 'Accounts matched by email', 'sentence' => 'Teme already had a person with this email address, so the WordPress account became theirs.'],
        'user.subscriber' => ['group' => 'Subscribers left behind', 'sentence' => 'Teme has no accounts for readers yet, so WordPress subscribers weren’t imported.'],
        'user.custom-role' => ['group' => 'Custom roles mapped', 'sentence' => 'A WordPress role isn’t one of Teme’s, so these people got the nearest Teme role.'],
        'user.credited' => ['group' => 'Posts credited to someone else', 'sentence' => 'Posts and pages were credited to the person chosen on the plan.'],
        'check.not-found' => ['group' => 'Addresses that answer “not found”', 'sentence' => 'An old address doesn’t answer on Teme yet. Fix it before you switch.'],
        'media.download-failed' => ['group' => 'Media that didn’t download', 'sentence' => 'A file couldn’t be downloaded from the old site, so it wasn’t imported. The next run tries again.'],
        'object.refused' => ['group' => 'Items Teme refused', 'sentence' => 'Teme wouldn’t accept this item as it stood on WordPress.'],
        'content.shortcode-removed' => ['group' => 'Shortcodes removed', 'sentence' => 'A shortcode’s plugin isn’t on Teme, so the shortcode was taken out of the content.'],
        'widgets.reported' => ['group' => 'Widgets not imported', 'sentence' => 'Sidebar widgets aren’t brought across. Set up the theme’s regions again.'],
        'post.slug-changed' => ['group' => 'Addresses changed', 'sentence' => 'WordPress’s address ending couldn’t be kept, so Teme chose another.'],
        'post.changed-here' => ['group' => 'Changed here since', 'sentence' => 'It was edited on Teme after an earlier import, so WordPress’s later changes weren’t brought across.'],
        'post.deleted-here' => ['group' => 'Deleted here since', 'sentence' => 'It was deleted on Teme after an earlier import, so it wasn’t imported again.'],
        'redirect.regex' => ['group' => 'Redirect patterns left out', 'sentence' => 'A redirect’s pattern can’t be written as Teme’s. Add it in Tools › Redirects.'],
        'setting.kept-here' => ['group' => 'Settings kept as they are here', 'sentence' => 'These settings were changed on Teme since the last import, so they stay as they are.'],
        'menu.not-placed' => ['group' => 'Menus not placed', 'sentence' => 'A menu isn’t shown anywhere yet: choose its place under Content › Navigation.'],
        'menus.unplaced' => ['group' => 'Menus not placed', 'sentence' => 'Menus came across; place them under Content › Navigation.'],
        'catch-up.deleted' => ['group' => 'Gone on WordPress', 'sentence' => 'WordPress no longer has this. Nothing was deleted here; it is only listed.'],
        'attachment.duplicate' => ['group' => 'Duplicate media skipped', 'sentence' => 'The same file was already on Teme, so this copy wasn’t imported again.'],
        'seo.variables' => ['group' => 'SEO variables not kept', 'sentence' => 'WordPress SEO placeholders Teme doesn’t use were left out.'],
        'plugin-data.skipped' => ['group' => 'Plugin data skipped', 'sentence' => 'A plugin’s data wasn’t brought across for this record.'],
        'plugin-data.companion-too-old' => ['group' => 'Companion too old for plugin data', 'sentence' => 'Update the companion on WordPress, then catch up.'],
    ];

    /**
     * Returns the plain group name and sentence for a message code. The
     * sentence is null when the report should keep the first message's words.
     *
     * @return array{group: string, sentence: string|null}
     */
    public static function for(string $code): array
    {
        $row = self::GROUPS[$code] ?? null;

        if ($row !== null) {
            return [
                'group' => __($row['group']),
                'sentence' => __($row['sentence']),
            ];
        }

        $tail = str_contains($code, '.') ? substr($code, (int) strrpos($code, '.') + 1) : $code;

        return [
            'group' => __(ucfirst(str_replace('-', ' ', $tail))),
            'sentence' => null,
        ];
    }
}
