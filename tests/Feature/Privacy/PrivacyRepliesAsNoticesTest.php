<?php

declare(strict_types=1);

use App\Domain\Notices\NoticeRegistry;
use App\Domain\Notices\SentAsNotice;
use App\Domain\Privacy\ExportFile;
use App\Domain\Privacy\Notifications\PersonalDataErased;
use App\Domain\Privacy\Notifications\PersonalDataExported;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Notices\NoticeKind;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    cmSettings(['site_title' => 'Plant *Notes*'], 'general');
});

it('registers the two replies as required notice kinds, by mail only, with no audience and their reasons', function (string $key, string $reason): void {
    $kind = app(NoticeRegistry::class)->get($key);

    expect($kind)->toBeInstanceOf(NoticeKind::class)
        ->and($kind->required)->toBeTrue()
        ->and($kind->channels)->toBe([NoticeKind::MAIL])
        ->and($kind->audience)->toBeNull()
        ->and($kind->reason)->toBe($reason)
        ->and(app(NoticeRegistry::class)->listed())->not->toContain($kind);
})->with([
    'the export' => ['privacy.exported', 'You get this email because you asked this site for your personal data.'],
    'the erasure' => ['privacy.erased', 'You get this email because you asked this site to erase your personal data.'],
]);

it('sends each reply as a notice of its kind, to a bare address only because the kind is required', function (object $notice, string $kind): void {
    expect(class_uses($notice))->toContain(SentAsNotice::class)
        ->and($notice->noticeKind())->toBe($kind)
        ->and($notice->via((new AnonymousNotifiable)->route('mail', 'ada@example.com')))->toBe(['mail']);
})->with([
    'the export' => [fn (): object => new PersonalDataExported('privacy/x.json'), 'privacy.exported'],
    'the erasure' => [fn (): object => new PersonalDataErased([]), 'privacy.erased'],
]);

it('still sends each reply to the address when the queued job runs, after it is serialised and restored', function (object $notice): void {
    Queue::fake();
    $sent = [];
    Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
        $sent[] = [$event->channel, $event->notifiable->routes['mail'] ?? null];
    });
    Storage::disk(ExportFile::DISK)->put('privacy/x.json', '{}');

    Notification::route('mail', 'ada@example.com')->notify($notice);

    $pushed = Queue::pushed(SendQueuedNotifications::class)->all();

    expect($pushed)->toHaveCount(1);

    foreach ($pushed as $job) {
        unserialize(serialize($job))->handle(app(ChannelManager::class));
    }

    expect($sent)->toBe([['mail', 'ada@example.com']]);
})->with([
    'the export' => [fn (): object => new PersonalDataExported('privacy/x.json')],
    'the erasure' => [fn (): object => new PersonalDataErased(['Kept.'])],
]);

it('gives each reply’s footer from the layout, not as a line of the reply', function (object $notice, string $footer): void {
    Storage::disk(ExportFile::DISK)->put('privacy/x.json', '{}');
    $mail = $notice->toMail((new AnonymousNotifiable)->route('mail', 'ada@example.com'));

    expect($mail->viewData['footer'])->toBe([$footer])
        ->and($mail->introLines)->not->toContain($footer)
        ->and($mail->salutation)->toBe('Plant \\*Notes\\*');
})->with([
    'the export' => [fn (): object => new PersonalDataExported('privacy/x.json'), 'You get this email because you asked this site for your personal data.'],
    'the erasure' => [fn (): object => new PersonalDataErased(['Kept.']), 'You get this email because you asked this site to erase your personal data.'],
]);

it('puts every reason in the erased email as words, a plugin’s included', function (): void {
    $mail = (new PersonalDataErased(['*Bold* and [a link](https://example.com).', '# Heading', 'Plain.']))
        ->toMail((new AnonymousNotifiable)->route('mail', 'ada@example.com'));

    expect($mail->introLines)->toContain('\\*Bold\\* and \\[a link\\](https://example.com).')
        ->and($mail->introLines)->toContain('\\# Heading')
        ->and($mail->introLines)->toContain('Plain.');
});
