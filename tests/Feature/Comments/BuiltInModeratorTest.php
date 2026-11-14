<?php

declare(strict_types=1);

use App\Domain\Comments\BuiltInModerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Comments\CommentModerator;
use Teme\Contracts\Comments\ModerationVerdict;

uses(RefreshDatabase::class);

it('is the moderator until a plugin binds its own (I-224)', function (): void {
    expect(app(CommentModerator::class))->toBeInstanceOf(BuiltInModerator::class)
        ->and(app(CommentModerator::class)->name())->toBe('Teme');
});

it('sends a comment the block list matches to spam before anything else, naming the words and where they were found', function (): void {
    cmSettings(['block_list' => "bet9ja-promo.example\ninstant loans", 'hold_list' => 'loans']);

    $verdict = app(CommentModerator::class)->judge(cmCandidate(['text' => 'Try INSTANT LOANS today', 'returning' => true]));

    expect($verdict->status)->toBe(ModerationVerdict::SPAM)
        ->and($verdict->signals)->toBe(['rule' => 'block-list', 'match' => 'instant loans', 'field' => 'text'])
        ->and($verdict->reason)->toBe('Sent to spam: the block list has “instant loans”, found in the comment.');
});

it('looks for a list\'s words in the name, email address, website, IP address and browser too (I-573)', function (string $line, array $candidate, string $field): void {
    cmSettings(['hold_list' => $line]);

    $verdict = app(CommentModerator::class)->judge(cmCandidate([...$candidate, 'returning' => true]));

    expect($verdict->status)->toBe(ModerationVerdict::PENDING)
        ->and($verdict->signals)->toBe(['rule' => 'hold-list', 'match' => $line, 'field' => $field]);
})->with([
    'the name' => ['casino', ['authorName' => 'Casino Kid'], 'name'],
    'the email address' => ['casino', ['authorEmail' => 'kid@casino.example'], 'email'],
    'the website' => ['casino', ['authorUrl' => 'https://casino.example'], 'website'],
    'a range of IP addresses' => ['198.51.100.', ['ip' => '198.51.100.23'], 'ip'],
    'the browser' => ['spambot', ['userAgent' => 'SpamBot/2.0'], 'browser'],
]);

it('holds a comment with as many links as the setting, and none for its links when it is 0', function (): void {
    $moderator = app(CommentModerator::class);

    expect($moderator->judge(cmCandidate(['links' => 2, 'returning' => true]))->signals)->toBe(['rule' => 'links', 'links' => 2])
        ->and($moderator->judge(cmCandidate(['links' => 1, 'returning' => true]))->status)->toBe(ModerationVerdict::APPROVED);

    cmSettings(['hold_links' => 0]);

    expect(app(CommentModerator::class)->judge(cmCandidate(['links' => 7, 'returning' => true]))->status)->toBe(ModerationVerdict::APPROVED);
});

it('holds every comment when told to, returning writers\' too', function (): void {
    cmSettings(['hold_all' => true]);

    expect(app(CommentModerator::class)->judge(cmCandidate(['returning' => true]))->signals)->toBe(['rule' => 'hold-all']);
});

it('approves a returning writer and holds a first comment once (I-275)', function (): void {
    $moderator = app(CommentModerator::class);

    expect($moderator->judge(cmCandidate(['returning' => true])))->toEqual(new ModerationVerdict(ModerationVerdict::APPROVED, 'Approved: a returning commenter.', ['rule' => 'returning']))
        ->and($moderator->judge(cmCandidate()))->toEqual(new ModerationVerdict(ModerationVerdict::PENDING, 'Held: a first comment, or the first from this browser.', ['rule' => 'first-comment']));
});

it('approves what passes the lists when returning writers aren\'t asked for, as WordPress does with that option off (D11)', function (): void {
    cmSettings(['approve_returning' => false]);

    expect(app(CommentModerator::class)->judge(cmCandidate()))->toEqual(new ModerationVerdict(ModerationVerdict::APPROVED, 'Approved: it passed every check.', ['rule' => 'passed']));
});
