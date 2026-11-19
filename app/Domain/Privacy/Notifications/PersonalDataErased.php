<?php

/**
 * The queued notice telling a person their personal data was erased, and
 * what was kept and why (I-3375). Every reason goes through MailText, so a
 * plugin's words never become Markdown.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Notifications;

use App\Domain\Notices\MailText;
use App\Domain\Notices\NoticeLayout;
use App\Domain\Notices\SentAsNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Teme\Contracts\Settings\SettingsReader;

final class PersonalDataErased extends Notification implements ShouldQueue
{
    use Queueable, SentAsNotice;

    /**
     * @param  list<string>  $reasons  why anything was kept; each is made plain here, so a plugin's words stay words
     */
    public function __construct(public readonly array $reasons) {}

    public function noticeKind(): string
    {
        return 'privacy.erased';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = (string) app(SettingsReader::class)->string('general', 'site_title');
        $mail = (new MailMessage)
            ->subject(__('Your personal data on :site was erased', ['site' => $site]))
            ->line(__('You asked for your personal data on this site to be erased, and it has been.'));

        if ($this->reasons !== []) {
            $mail->line(__('Some was kept:'));

            foreach ($this->reasons as $reason) {
                $mail->line(MailText::inline($reason));
            }
        }

        $mail->line(__("The site's backups may still hold this data until they are replaced."));

        return NoticeLayout::apply($mail, $this->noticeKind(), $notifiable);
    }
}
