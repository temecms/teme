<?php

/**
 * The administrators' email about the backups (I-2409): what failed, in
 * the verdict's own words, the newest good backup, what to run at the
 * server and when the next email comes; or that backups work again. Sent
 * at once by SendBackupAlertEmails, which is itself the queued job.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Backups\Notifications;

use App\Domain\Backups\BackupAlertKind;
use App\Domain\Backups\BackupSettings;
use App\Domain\Notices\MailText;
use App\Domain\Notices\NoticeLayout;
use App\Domain\Notices\SentAsNotice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class BackupAlertNotice extends Notification
{
    use SentAsNotice;

    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public readonly BackupAlertKind $kind,
        public readonly array $problems,
        public readonly string $site,
        public readonly ?string $lastGood,
        public readonly ?string $since,
    ) {}

    public function noticeKind(): string
    {
        return 'backups.alert';
    }

    public function toMail(object $notifiable): MailMessage
    {
        // A mail header takes the title as typed; Markdown lines get it escaped.
        $site = MailText::inline($this->site);
        $mail = new MailMessage;

        if ($this->kind === BackupAlertKind::Fixed) {
            $mail->subject(__('Backups of :site are working again', ['site' => $this->site]))
                ->line(__('The backups of :site are working again.', ['site' => $site]));

            if ($this->lastGood !== null) {
                $mail->line(__('The newest backup is from :when, and its checks passed.', ['when' => $this->lastGood]));
            }

            if ($this->since !== null) {
                $mail->line(__('They had been failing since :when.', ['when' => $this->since]));
            }

            return NoticeLayout::apply($mail->action(__('See the backups'), route(BackupSettings::SCREEN)), $this->noticeKind(), $notifiable);
        }

        $mail->subject($this->kind === BackupAlertKind::Failing
            ? __('Backups of :site are failing', ['site' => $this->site])
            : __('Backups of :site are still failing', ['site' => $this->site]));
        $mail->line(match (true) {
            $this->kind === BackupAlertKind::Failing => __('Something has gone wrong with the backups of :site:', ['site' => $site]),
            $this->since !== null => __('The backups of :site are still failing, as they have been since :when:', ['site' => $site, 'when' => $this->since]),
            default => __('The backups of :site are still failing:', ['site' => $site]),
        });

        foreach ($this->problems as $problem) {
            $mail->line(MailText::inline($problem));
        }

        $mail
            ->line($this->lastGood === null ? __('There’s no good backup yet.') : __('The newest good backup is from :when.', ['when' => $this->lastGood]))
            ->line(__('At the server, php artisan teme:backup:run backs the site up now, and php artisan teme:backup:verify checks the backups again. The log there has restic’s and PostgreSQL’s own words.'))
            ->line(__('While the problem lasts, a reminder comes once a day, and another email says when backups work again.'))
            ->action(__('See the backups'), route(BackupSettings::SCREEN));

        return NoticeLayout::apply($mail, $this->noticeKind(), $notifiable);
    }
}
