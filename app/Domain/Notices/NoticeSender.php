<?php

/**
 * Sends a plain message of a declared kind, after the current transaction
 * commits and never if it rolls back (rule 6). Who receives it is
 * NoticeChoices' decision, made when each notification is sent.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Notices;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Teme\Contracts\Notices\NoticeKind;
use Teme\Contracts\Notices\NoticeMessage;
use Teme\Contracts\Notices\Notices;

final class NoticeSender implements Notices
{
    public function __construct(private readonly NoticeRegistry $registry) {}

    public function send(string $kind, array $accountIds, NoticeMessage $message): void
    {
        $this->check($kind, $message);

        DB::afterCommit(function () use ($kind, $accountIds, $message): void {
            $users = User::query()->whereKey($accountIds)->orderBy('id')->get();

            Notification::send($users, new NoticeMail($kind, $message));
        });
    }

    public function sendToAddress(string $kind, string $email, NoticeMessage $message): void
    {
        $declared = $this->check($kind, $message);

        if (! $declared->required || ! in_array(NoticeKind::MAIL, $declared->channels, true)) {
            throw new InvalidArgumentException("Notice kind [{$kind}] can't be sent to an address: only a required email kind can.");
        }

        DB::afterCommit(function () use ($kind, $email, $message): void {
            Notification::route('mail', $email)->notify(new NoticeMail($kind, $message));
        });
    }

    public function sendToAudience(string $kind, NoticeMessage $message): void
    {
        $declared = $this->check($kind, $message);

        if ($declared->audience === null || $declared->audience === '') {
            throw new InvalidArgumentException("Notice kind [{$kind}] has no audience to send to.");
        }

        $ids = User::permission($declared->audience)
            ->orderBy('id')
            ->pluck('id')
            ->map(strval(...))
            ->all();

        $this->send($kind, $ids, $message);
    }

    private function check(string $kind, NoticeMessage $message): NoticeKind
    {
        $declared = $this->registry->get($kind) ?? throw new InvalidArgumentException("No notice kind is called [{$kind}].");

        if ($message->actionRoute !== null && ! Route::has($message->actionRoute)) {
            throw new InvalidArgumentException("No route is named [{$message->actionRoute}].");
        }

        return $declared;
    }
}
