<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Cache\PageTags;
use App\Domain\Types\ContentTypeRegistry;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;

/**
 * The `discussion` settings group (docs/studies/comments.md §3.10): who may
 * comment, which comments wait for approval, and how deep replies go. Built
 * from the type registry, as the `addresses` group is, so each type code
 * declares that takes comments is a choice for new items open to them; a
 * type saved on the site sets that on its own screen. A part declares
 * what it reads (D6 of 1.8a): 1.8b adds `ask_website` and `per_page`, and
 * the tag every page that draws a thread carries; 1.8d the emails,
 * `keep_ip_days` and the screen that edits them (Settings › Discussion).
 */
final class DiscussionSettingsGroup
{
    public static function build(ContentTypeRegistry $types): SettingsGroup
    {
        $options = [];
        $open = [];

        foreach ($types->all() as $type) {
            // A stored type says on its own screen how its items start (I-411).
            if ($type->comments === null || $type->stored) {
                continue;
            }

            $options[$type->key] = $type->plural;

            if ($type->comments === Discussion::Open) {
                $open[] = $type->key;
            }
        }

        return new SettingsGroup(
            key: 'discussion',
            label: 'Discussion',
            description: 'Whether the site has comments, who can comment, which comments wait for approval, and the emails they send.',
            section: 'discussion',
            ability: 'settings.discussion.manage',
            settings: [
                Setting::multiselect('open_for', 'New items are open for comments', 'The types whose new items take comments as they start. Unticked, new items start Closed. Each item can still be set to Open, Closed or Hidden on its own.', '0.1.0', $options)
                    ->default($open),
                Setting::boolean('signed_in_only', 'Only signed-in people can comment', 'Off, guests comment with a name and email address. Readers can\'t have accounts yet, so on limits comments to the site\'s own people.', '0.1.0'),
                Setting::boolean('ask_website', 'Ask guests for their website', 'On, the comment form has an optional Website field, and a guest’s name links to it.', '0.1.0')
                    ->default(true),
                Setting::number('close_after_days', 'Close comments after', 'Days after an item is published when it stops taking new comments; its comments stay shown. 0 keeps comments open.', '0.1.0')
                    ->default(0)->min(0)->max(3650)->unit('days'),
                Setting::number('thread_depth', 'Thread depth', 'How deep replies go, up to 10. A reply to a comment at the deepest level is placed beside it.', '0.1.0')
                    ->default(5)->min(1)->max(10)->unit('levels'),
                Setting::number('per_page', 'Comments per page', 'Comments that start a thread, oldest first, each with its replies. The first page is part of the article, so search engines read it.', '0.1.0')
                    ->default(50)->min(1)->max(200)->unit('comments'),
                Setting::boolean('hold_all', 'Hold every comment for approval', 'Every new comment waits until someone approves it.', '0.1.0'),
                Setting::boolean('approve_returning', 'Approve returning commenters', 'On, a comment is approved at once when its email address has an approved comment sent from the same browser, and a first comment waits once.', '0.1.0')
                    ->default(true),
                Setting::number('hold_links', 'Hold comments with at least this many links', 'Many links are a sign of spam. 0 holds no comment for its links.', '0.1.0')
                    ->default(2)->min(0)->max(100)->unit('links'),
                Setting::textarea('hold_list', 'Hold list', 'One word or phrase a line. A comment whose words, name, email address, website, IP address or browser holds one waits for approval.', '0.1.0')
                    ->maxLength(30000),
                Setting::textarea('block_list', 'Block list', 'One word or phrase a line. A comment that holds one goes straight to spam.', '0.1.0')
                    ->maxLength(30000),
                Setting::boolean('email_moderators', 'Email moderators when a comment waits for approval', 'Sent to editors and administrators, and to the author of the post or page if they can publish it.', '0.1.0')
                    ->default(true),
                Setting::boolean('email_authors', 'Email the author when a comment on their post or page is approved', 'Each person can turn either email off for themselves in Account › Notifications.', '0.1.0')
                    ->default(true),
                Setting::number('keep_ip_days', 'Keep commenters’ IP addresses for', 'Each comment’s IP address and browser are erased after this many days, imported comments’ too. Only editors and administrators see them.', '0.1.0')
                    ->default(60)->min(1)->max(365)->unit('days'),
            ],
            since: '0.1.0',
            screen: 'settings.discussion',
            cacheTags: [PageTags::DISCUSSION],
        );
    }
}
