<?php

declare(strict_types=1);

namespace App\Domain\Notices;

use Illuminate\Contracts\View\View;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Gives every mail view the site's title, so an email no kind sends, such as
 * Laravel's verification email, wears the same header, sign-off and footer
 * as the rest (I-772). `$site` is the title as typed, for a header or a
 * plain-text line; `$siteLine` is the same title made safe for Markdown.
 */
final class MailComposer
{
    public function __construct(private readonly SettingsReader $settings) {}

    public function compose(View $view): void
    {
        $site = trim((string) $this->settings->string('general', 'site_title'));

        $view->with('site', $site)->with('siteLine', MailText::inline($site));
    }
}
