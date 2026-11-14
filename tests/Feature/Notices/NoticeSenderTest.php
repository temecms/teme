<?php

declare(strict_types=1);

use App\Domain\Inbox\Models\InboxItem;
use App\Domain\Notices\Channels\InboxChannel;
use App\Domain\Notices\NoticeMail;
use App\Domain\Notices\NoticePreferences;
use App\Domain\Notices\NoticeSender;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Notices\NoticeKind;
use Teme\Contracts\Notices\NoticeMessage;
use Teme\Contracts\Notices\NoticeRegistrar;
use Teme\Contracts\Notices\Notices;
use Tests\Fixtures\Notices\TwoChannelKind;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    TwoChannelKind::register();
    $this->notices = app(Notices::class);
    $this->message = new NoticeMessage('Your *export* is ready', ['It took [a while](https://evil.example).', '# Done'], 'Open it', 'inbox.index', quote: "A word\n\nfrom someone");
    $this->ada = User::factory()->create(['name' => 'Ada']);
    $this->grace = User::factory()->create(['name' => 'Grace']);
});

it('resolves the contract to core’s sender', function (): void {
    expect($this->notices)->toBeInstanceOf(NoticeSender::class);
});

it('mails each account that may receive the kind after the commit, and none that chose nothing', function (): void {
    Notification::fake();
    lscrPrefer($this->grace, [NoticePreferences::PREFIX.TwoChannelKind::KEY => []]);
    $suspended = User::factory()->create(['suspended_at' => now()]);

    DB::transaction(function () use ($suspended): void {
        $this->notices->send(TwoChannelKind::KEY, [(string) $this->ada->id, (string) $this->grace->id, (string) $suspended->id], $this->message);

        Notification::assertNothingSent();
    });

    Notification::assertSentTo($this->ada, NoticeMail::class);
    Notification::assertNotSentTo($this->grace, NoticeMail::class);
    Notification::assertNotSentTo($suspended, NoticeMail::class);
});

it('sends nothing from a transaction that rolls back', function (): void {
    Notification::fake();

    try {
        DB::transaction(function (): void {
            $this->notices->send(TwoChannelKind::KEY, [(string) $this->ada->id], $this->message);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    Notification::assertNothingSent();
});

it('draws the message as plain words in the layout, its action an address on the configured one', function (): void {
    $html = (string) (new NoticeMail(TwoChannelKind::KEY, $this->message))->toMail($this->ada)->render();

    expect($html)->toContain('Hi Ada,')
        ->and($html)->toContain('It took [a while](https://evil.example).')
        ->and($html)->not->toContain('href="https://evil.example"')
        ->and($html)->toContain('href="http://localhost/admin/inbox"')
        ->and($html)->toContain('To stop it, turn it off in');
});

it('puts one inbox item in the inbox of a person keeping both channels, as that person, and mails them', function (): void {
    app()->instance(Elevation::class, new DeniedElevation);
    $long = new NoticeMessage(str_repeat('t', 300), [str_repeat('x', 1200)], 'Open it', 'inbox.index');
    Notification::fake();

    $this->notices->send(TwoChannelKind::KEY, [(string) $this->ada->id], $long);

    Notification::assertSentTo($this->ada, NoticeMail::class, fn (NoticeMail $mail, array $channels): bool => in_array('mail', $channels, true) && count($channels) === 2);

    $notice = new NoticeMail(TwoChannelKind::KEY, $long);
    app(InboxChannel::class)->send($this->ada, $notice);

    $item = InboxItem::query()->where('user_id', $this->ada->id)->sole();

    expect($item->kind)->toBe('tests.two-channels')
        ->and($item->owner)->toBe('core')
        ->and(strlen($item->body['title']))->toBe(250)
        ->and(strlen($item->body['text']))->toBe(1000)
        ->and($item->body['link']['route'])->toBe('inbox.index');
});

it('refuses an unregistered kind or an action naming no route before anything is queued', function (): void {
    Notification::fake();

    expect(fn () => $this->notices->send('no.such_kind', [(string) $this->ada->id], $this->message))->toThrow(InvalidArgumentException::class, 'No notice kind is called [no.such_kind].')
        ->and(fn () => $this->notices->send(TwoChannelKind::KEY, [(string) $this->ada->id], new NoticeMessage('S', [], 'Go', 'no.such.route')))->toThrow(InvalidArgumentException::class, 'No route is named [no.such.route].');

    Notification::assertNothingSent();
});

it('mails an address for a required kind, and refuses a listed kind and a required inbox-only kind', function (): void {
    app(NoticeRegistrar::class)->register(new NoticeKind('tests.must_mail', 'Must mail', channels: [NoticeKind::MAIL], defaults: [NoticeKind::MAIL], required: true));
    app(NoticeRegistrar::class)->register(new NoticeKind('tests.must_inbox', 'Must inbox', channels: [NoticeKind::INBOX], defaults: [NoticeKind::INBOX], required: true));
    Notification::fake();

    $this->notices->sendToAddress('tests.must_mail', 'someone@example.com', new NoticeMessage('Hello', ['A line.']));

    Notification::assertSentTo(new AnonymousNotifiable, NoticeMail::class, fn (NoticeMail $mail, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'someone@example.com');

    expect(fn () => $this->notices->sendToAddress(TwoChannelKind::KEY, 'someone@example.com', $this->message))->toThrow(InvalidArgumentException::class, 'can\'t be sent to an address')
        ->and(fn () => $this->notices->sendToAddress('tests.must_inbox', 'someone@example.com', $this->message))->toThrow(InvalidArgumentException::class, 'can\'t be sent to an address');
});

it('sends a queued notice by the choice the person holds when the worker runs, not the one they held when it was queued', function (bool $turnOff): void {
    Queue::fake();
    $sent = [];
    Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
        $sent[] = $event->channel;
    });

    $this->notices->send(TwoChannelKind::KEY, [(string) $this->ada->id], new NoticeMessage('Hello', ['A line.']));

    $pushed = Queue::pushed(SendQueuedNotifications::class)->all();

    expect($pushed)->not->toBeEmpty();

    if ($turnOff) {
        lscrPrefer($this->ada, [NoticePreferences::PREFIX.TwoChannelKind::KEY => []]);
    }

    // As the worker gets it: serialised on the queue, the account read afresh.
    foreach ($pushed as $job) {
        unserialize(serialize($job))->handle(app(ChannelManager::class));
    }

    expect($sent)->toBe($turnOff ? [] : ['mail', InboxChannel::class]);
})->with([
    'still on' => [false],
    'turned off after it was queued' => [true],
]);

it('still sends a required kind with no audience when the worker runs, whatever became of the account', function (): void {
    Queue::fake();
    $sent = [];
    Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
        $sent[] = $event->channel;
    });
    app(NoticeRegistrar::class)->register(new NoticeKind('tests.must_mail', 'Must mail', required: true));

    $this->notices->send('tests.must_mail', [(string) $this->ada->id], new NoticeMessage('Hello', ['A line.']));
    $this->ada->forceFill(['suspended_at' => now()])->save();

    foreach (Queue::pushed(SendQueuedNotifications::class)->all() as $job) {
        $job->handle(app(ChannelManager::class));
    }

    expect($sent)->toBe(['mail']);
});
