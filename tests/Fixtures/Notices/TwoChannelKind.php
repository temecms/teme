<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notices;

use App\Domain\Notices\NoticeRegistry;
use App\Domain\Preferences\PreferenceRegistry;
use Teme\Contracts\Notices\NoticeKind;

/**
 * A test kind that comes by email and to the inbox, both on until chosen
 * otherwise, which no core kind is yet.
 */
final class TwoChannelKind
{
    public const string KEY = 'tests.two_channels';

    /**
     * Declares the kind and rebuilds the preference registry so its choice exists.
     */
    public static function register(): NoticeKind
    {
        $kind = new NoticeKind(self::KEY, 'Tell me about the test', channels: [NoticeKind::MAIL, NoticeKind::INBOX], defaults: [NoticeKind::MAIL, NoticeKind::INBOX]);
        app(NoticeRegistry::class)->register($kind);
        app()->forgetInstance(PreferenceRegistry::class);

        return $kind;
    }
}
