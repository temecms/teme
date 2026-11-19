<?php

/**
 * What makes a Laravel notification one of Teme's notices: it names its kind,
 * and the channels it goes by are whatever NoticeChoices says this recipient
 * gets that kind by. Laravel decides a queued notification's channels once,
 * when it queues it, so shouldSend() asks again as the worker sends each one:
 * a choice made, or an account suspended, in between is honoured.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Notices;

use App\Domain\Notices\Channels\InboxChannel;
use App\Domain\Notices\Queries\NoticeChoices;
use Teme\Contracts\Notices\NoticeKind;

trait SentAsNotice
{
    /**
     * The key of the kind this notification is an instance of.
     */
    abstract public function noticeKind(): string;

    /**
     * The Laravel channels for this recipient: none when they may not receive it.
     *
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        if (! is_object($notifiable)) {
            return [];
        }

        return array_map(
            fn (string $channel): string => $channel === NoticeKind::INBOX ? InboxChannel::class : 'mail',
            app(NoticeChoices::class)->channelsFor($notifiable, $this->noticeKind()),
        );
    }

    /**
     * Whether the worker should still send this notice by $channel, asked
     * again for this recipient when it runs.
     */
    public function shouldSend(mixed $notifiable, string $channel): bool
    {
        return in_array($channel, $this->via($notifiable), true);
    }
}
