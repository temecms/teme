<?php

declare(strict_types=1);

use App\Domain\Notices\NoticeRegistry;
use Teme\Contracts\Notices\NoticeKind;
use Teme\Contracts\Notices\NoticeRegistrar;

it('declares core’s nine kinds in order with their flags', function (): void {
    $registry = app(NoticeRegistry::class);

    expect(array_keys($registry->all()))->toBe([
        'comments.waiting', 'comments.approved', 'backups.alert', 'updates.update',
        'users.invitation', 'users.password_changed', 'users.password_reset',
        'privacy.exported', 'privacy.erased',
    ]);

    $waiting = $registry->get('comments.waiting');
    expect($waiting->required)->toBeFalse()
        ->and($waiting->audience)->toBe('look-after-comments')
        ->and($waiting->channels)->toBe(['mail'])
        ->and($waiting->defaults)->toBe(['mail'])
        ->and($waiting->reason)->toBe('You get this email because you look after comments on :site.');

    $approved = $registry->get('comments.approved');
    expect($approved->required)->toBeFalse()
        ->and($approved->audience)->toBe('content.create')
        ->and($approved->reason)->toBe('You get this email because you wrote this :word.');

    foreach (['backups.alert' => 'backups.manage', 'updates.update' => 'updates.manage'] as $key => $audience) {
        $kind = $registry->get($key);
        expect($kind->required)->toBeTrue()
            ->and($kind->audience)->toBe($audience)
            ->and($kind->reason)->toBe('Every administrator of :site gets this email.');
    }

    foreach (['users.invitation', 'users.password_changed', 'users.password_reset'] as $key) {
        $kind = $registry->get($key);
        expect($kind->required)->toBeTrue()
            ->and($kind->audience)->toBeNull()
            ->and($kind->reason)->toBe('');
    }

    expect(array_map(fn (NoticeKind $kind): string => $kind->key, $registry->listed()))->toBe(['comments.waiting', 'comments.approved']);
});

it('refuses a kind that breaks a rule, naming it', function (NoticeKind $kind, string $message): void {
    $registry = new NoticeRegistry;
    $registry->register(new NoticeKind('tests.one', 'One'));

    expect(fn () => $registry->register($kind))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a hyphen in the key' => [new NoticeKind('tests.two-words', 'Two'), 'Notice kind [tests.two-words] must be lower-case words and underscores, in two parts or more, joined by dots.'],
    'one part only' => [new NoticeKind('tests', 'Two'), 'Notice kind [tests] must be lower-case words and underscores'],
    'a duplicate' => [new NoticeKind('tests.one', 'Two'), 'Notice kind [tests.one] is already declared.'],
    'a prefix of another' => [new NoticeKind('tests.one.deeper', 'Two'), 'cannot both be declared: one is the prefix of the other.'],
    'no label' => [new NoticeKind('tests.two', ' '), 'Notice kind [tests.two] needs a label.'],
    'an empty audience' => [new NoticeKind('tests.two', 'Two', audience: ''), 'Notice kind [tests.two] needs an audience.'],
    'no channels' => [new NoticeKind('tests.two', 'Two', channels: [], defaults: []), 'Notice kind [tests.two] has an unknown channel'],
    'a repeated channel' => [new NoticeKind('tests.two', 'Two', channels: ['mail', 'mail']), 'Notice kind [tests.two] has an unknown channel'],
    'an unknown channel' => [new NoticeKind('tests.two', 'Two', channels: ['sms'], defaults: []), 'Notice kind [tests.two] has an unknown channel [sms].'],
    'a default outside the channels' => [new NoticeKind('tests.two', 'Two', defaults: ['inbox']), 'Notice kind [tests.two] has a default that isn\'t one of its channels.'],
    'a required kind not fully on' => [new NoticeKind('tests.two', 'Two', channels: ['mail', 'inbox'], defaults: ['mail'], required: true), 'Notice kind [tests.two] is required, so every channel is on by default.'],
]);

it('allows a listed kind that is off until chosen', function (): void {
    $registry = new NoticeRegistry;
    $registry->register(new NoticeKind('tests.quiet', 'Quiet', defaults: []));

    expect($registry->get('tests.quiet')->defaults)->toBe([]);
});

it('resolves the registrar from the container to core’s registry', function (): void {
    expect(app(NoticeRegistrar::class))->toBeInstanceOf(NoticeRegistry::class)
        ->and(app(NoticeRegistrar::class))->toBe(app(NoticeRegistry::class));
});
