<?php

/**
 * The queued notice that sends a person the personal data they asked for,
 * as a JSON file read from the private disk when it is sent (I-3375). The
 * site's title is in the subject only, a header, never a Markdown line.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Privacy\Notifications;

use App\Domain\Notices\NoticeLayout;
use App\Domain\Notices\SentAsNotice;
use App\Domain\Privacy\ExportFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Settings\SettingsReader;

final class PersonalDataExported extends Notification implements ShouldQueue
{
    use Queueable, SentAsNotice;

    /** The largest file sent: most mail services refuse 25 MB. */
    public const int MOST_BYTES = 20 * 1024 * 1024;

    public function __construct(public readonly string $path) {}

    public function noticeKind(): string
    {
        return 'privacy.exported';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = (string) app(SettingsReader::class)->string('general', 'site_title');
        // Read as it is sent: the queued email can go after the file was deleted.
        $file = Storage::disk(ExportFile::DISK)->get($this->path);
        $mail = (new MailMessage)->subject(__('Your personal data from :site', ['site' => $site]));

        if ($file === null) {
            $mail->line(__('You asked for a copy of the personal data this site holds about you.'))
                ->line(__("The file was no longer on the site when this email was sent. The site's administrators can make it again."));
        } else {
            $mail->line(__('You asked for a copy of the personal data this site holds about you. It is attached, as a JSON file.'))
                ->attachData($file, 'personal-data.json', ['mime' => 'application/json']);
        }

        $mail->line(__("Some of it may also be in the site's backups until they are replaced."));

        return NoticeLayout::apply($mail, $this->noticeKind(), $notifiable);
    }
}
