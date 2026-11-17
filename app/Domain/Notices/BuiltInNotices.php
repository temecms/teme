<?php

declare(strict_types=1);

namespace App\Domain\Notices;

use App\Domain\Comments\CommentEmails;
use App\Domain\Comments\CommentModeration;
use App\Domain\Plugins\Area\CodeScripts;
use App\Domain\Settings\Modules;
use App\Domain\SiteCode\SiteCode;
use App\Models\User;
use Teme\Contracts\Notices\NoticeKind;

/**
 * The kinds of notice core sends (I-3375), in the order the account's
 * notifications screen lists the ones a person may turn off. The backup and
 * update emails stay required, as I-2409 and I-3326 decided.
 */
final class BuiltInNotices
{
    public static function register(NoticeRegistry $registry): void
    {
        $registry->register(new NoticeKind(
            CommentEmails::WAITING,
            'Email me when a comment waits for approval',
            audience: CommentModeration::LOOK_AFTER,
            reason: 'You get this email because you look after comments on :site.',
        ));
        $registry->rules(CommentEmails::WAITING, new NoticeRules(
            offered: fn (): bool => Modules::on(Modules::COMMENTS),
            sends: fn (): bool => CommentEmails::sends(CommentEmails::WAITING),
            switchedOff: 'Settings › Discussion has turned this email off for everyone.',
            labelFor: fn (User $user): string => $user->can(CommentModeration::EVERY_ITEM)
                ? __('Email me when a comment waits for approval')
                : __('Email me when a comment on my posts and pages waits for approval'),
        ));

        $registry->register(new NoticeKind(
            CommentEmails::APPROVED,
            'Email me when a comment on my posts and pages is approved',
            audience: 'content.create',
            reason: 'You get this email because you wrote this :word.',
        ));
        $registry->rules(CommentEmails::APPROVED, new NoticeRules(
            offered: fn (): bool => Modules::on(Modules::COMMENTS),
            sends: fn (): bool => CommentEmails::sends(CommentEmails::APPROVED),
            switchedOff: 'Settings › Discussion has turned this email off for everyone.',
        ));

        $registry->register(new NoticeKind(
            CodeScripts::NOTICE,
            'Email me when a plugin’s script waits for approval',
            audience: SiteCode::ABILITY,
            reason: 'You get this email because you may change the code of :site.',
        ));
        $registry->rules(CodeScripts::NOTICE, new NoticeRules(
            offered: fn (): bool => ! SiteCode::disallowed(),
        ));

        $registry->register(new NoticeKind('backups.alert', 'Backup alerts', audience: 'backups.manage', required: true, reason: 'Every administrator of :site gets this email.'));
        $registry->register(new NoticeKind('updates.update', 'Updates of Teme', audience: 'updates.manage', required: true, reason: 'Every administrator of :site gets this email.'));
        $registry->register(new NoticeKind('users.invitation', 'Invitations', required: true));
        $registry->register(new NoticeKind('users.password_changed', 'Password changed', required: true));
        $registry->register(new NoticeKind('users.password_reset', 'Password reset links', required: true));
        $registry->register(new NoticeKind('privacy.exported', 'Personal data exports', required: true, reason: 'You get this email because you asked this site for your personal data.'));
        $registry->register(new NoticeKind('privacy.erased', 'Personal data erasures', required: true, reason: 'You get this email because you asked this site to erase your personal data.'));
    }
}
