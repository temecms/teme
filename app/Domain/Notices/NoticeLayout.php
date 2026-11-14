<?php

declare(strict_types=1);

namespace App\Domain\Notices;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Puts the one email layout around a message (I-772): greeted by name,
 * signed with the site's title, and a footer saying why it came and, for a
 * kind a person may turn off, where to turn it off. It adds the recipient's
 * own name and nothing else about them.
 */
final class NoticeLayout
{
    /**
     * Sets the greeting, the sign-off and the footer on $mail.
     *
     * @param  string|null  $reason  the footer's reason in place of the kind's; '' for none
     * @param  array<string, string>  $replace  values for the reason's placeholders, made plain here
     */
    public static function apply(MailMessage $mail, string $kind, object $notifiable, ?string $reason = null, array $replace = []): MailMessage
    {
        $declared = app(NoticeRegistry::class)->get($kind);
        $site = MailText::inline(trim((string) app(SettingsReader::class)->string('general', 'site_title')));

        $values = ['site' => $site];

        foreach ($replace as $name => $value) {
            $values[$name] = MailText::inline($value);
        }

        $footer = [];
        $reason ??= $declared !== null ? $declared->reason : '';

        if ($reason !== '') {
            $footer[] = __($reason, $values);
        }

        if ($declared !== null && ! $declared->required) {
            $url = rtrim((string) config('app.url'), '/').route('notifications.edit', [], false);
            $footer[] = __('To stop it, turn it off in [your account’s notifications](:url).', ['url' => $url]);
        }

        if ($notifiable instanceof User) {
            $mail->greeting(__('Hi :name,', ['name' => MailText::inline($notifiable->name)]));
        }

        return $mail
            ->salutation($site)
            ->markdown('notifications::email', ['footer' => $footer]);
    }
}
