<?php

/**
 * Delivers a plain notice to the recipient's own inbox, as that person: a
 * person putting an item in their own inbox, with no elevation. Core's seven
 * kinds use mail only, so nothing in core reaches the inbox this way yet
 * (I-1921); a plugin's kind is filed under the plugin's code.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Notices\Channels;

use App\Domain\Inbox\InboxLink;
use App\Domain\Inbox\Operations\Input\SendToInboxInput;
use App\Domain\Inbox\Operations\SendToInbox;
use App\Domain\Notices\NoticeMail;
use App\Domain\Plugins\Notices\DeclaredNotices;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

final class InboxChannel
{
    public function __construct(private readonly SendToInbox $send) {}

    public function send(object $notifiable, Notification $notice): void
    {
        if (! $notifiable instanceof User || ! $notice instanceof NoticeMail) {
            return;
        }

        $message = $notice->message;
        $link = $message->actionRoute === null ? null : new InboxLink($message->actionRoute, $message->actionParameters);

        $this->send->execute(new SendToInboxInput(
            recipientId: (string) $notifiable->id,
            // The inbox's kinds allow hyphens and no underscore (SendToInbox::KEY).
            kind: str_replace('_', '-', $notice->noticeKind()),
            title: Str::limit($message->subject, 250, ''),
            text: Str::limit(implode(' ', $message->lines), 1000, ''),
            link: $link,
            actorId: (string) $notifiable->id,
            owner: $this->owner($notice->noticeKind()),
        ));
    }

    /**
     * Whom the item is from: the plugin's code for a plugin's own kind, as
     * the inbox shows it (docs/plans/plugin-sockets-3.md); core otherwise, and
     * for a code the inbox can't file.
     */
    private function owner(string $kind): string
    {
        $code = app(DeclaredNotices::class)->owner($kind)['code'] ?? null;

        return $code !== null && preg_match(SendToInbox::OWNER, $code) === 1 ? $code : 'core';
    }
}
