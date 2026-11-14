<?php

declare(strict_types=1);

namespace App\Domain\Updates\Notifications;

use App\Domain\Notices\MailText;
use App\Domain\Notices\NoticeLayout;
use App\Domain\Notices\SentAsNotice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The administrators' email about an update of Teme (I-3326): that it was asked for, that the updater began,
 * or how it ended. Sent at once by SendTemeUpdateEmails, which is itself the queued job.
 */
final class TemeUpdateNotice extends Notification
{
    use SentAsNotice;

    public const string REQUESTED = 'requested';

    public const string STARTED = 'started';

    public const string DONE = 'done';

    public const string SWITCHED_BACK = 'switched-back';

    public const string FAILED = 'failed';

    /**
     * @param  self::REQUESTED|self::STARTED|self::DONE|self::SWITCHED_BACK|self::FAILED  $kind
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $who,
        public readonly ?string $sentence,
        public readonly string $site,
        public readonly bool $rollback = false,
    ) {}

    public function noticeKind(): string
    {
        return 'updates.update';
    }

    public function toMail(object $notifiable): MailMessage
    {
        // A mail header takes the title as typed; Markdown lines get it escaped.
        $site = MailText::inline($this->site);
        $reason = $this->sentence === null ? '' : MailText::inline($this->sentence);
        $mail = new MailMessage;
        $replace = ['site' => $site, 'from' => $this->from, 'to' => $this->to, 'reason' => $reason];
        $subject = ['site' => $this->site, 'to' => $this->to];

        match ($this->kind) {
            self::STARTED => $mail
                ->subject($this->rollback ? __('The roll back of :site to Teme :to has begun', $subject) : __('The update of :site to Teme :to has begun', $subject))
                ->line($this->rollback
                    ? __('The updater began rolling back :site from Teme :from to :to. If no administrator asked for this, check Tools › Updates now.', $replace)
                    : __('The updater began updating :site from Teme :from to :to. If no administrator asked for this, check Tools › Updates now.', $replace)),
            self::REQUESTED => $mail
                ->subject(__('Teme :to was requested for :site', $subject))
                ->line($this->requestedLine($replace)),
            self::DONE => $mail
                ->subject(__(':site now runs Teme :to', $subject))
                ->line($this->rollback
                    ? __(':site was rolled back from Teme :from to :to.', $replace)
                    : __(':site was updated from Teme :from to :to.', $replace)),
            self::SWITCHED_BACK => $mail
                ->subject($this->rollback ? __('The roll back of :site to Teme :to was switched back', $subject) : __('The update of :site to Teme :to was switched back', $subject))
                ->line(__(':reason Every part of :site runs Teme :from again.', $replace)),
            default => $mail
                ->subject($this->rollback ? __('The roll back of :site to Teme :to stopped', $subject) : __('The update of :site to Teme :to stopped', $subject))
                ->line(__(':reason :site still runs Teme :from.', $replace)),
        };

        // Only the requested email says who gets it, as it always has; the others keep no footer reason.
        return NoticeLayout::apply($mail->action(__('See the update'), route('tools.updates.index')), $this->noticeKind(), $notifiable, $this->kind === self::REQUESTED ? null : '');
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function requestedLine(array $replace): string
    {
        $replace['name'] = $this->who === null ? '' : MailText::inline($this->who);

        if ($this->who === null) {
            return $this->rollback
                ? __('Someone at the server asked for :site to be rolled back from Teme :from to :to. The updater starts within a minute; until then it can be cancelled on Tools › Updates.', $replace)
                : __('Someone at the server asked for :site to be updated from Teme :from to :to. The updater starts within a minute; until then it can be cancelled on Tools › Updates.', $replace);
        }

        return $this->rollback
            ? __(':name asked for :site to be rolled back from Teme :from to :to. The updater starts within a minute; until then it can be cancelled on Tools › Updates.', $replace)
            : __(':name asked for :site to be updated from Teme :from to :to. The updater starts within a minute; until then it can be cancelled on Tools › Updates.', $replace);
    }
}
