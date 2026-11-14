<?php

declare(strict_types=1);

use App\Domain\Comments\CommentText;
use App\Domain\Comments\CommentVerdict;
use App\Domain\Comments\Queries\CommentDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->hers = cmItem($this->author, 'Harbour news');
    $this->his = cmItem($this->editor, 'Market day');
    $this->find = fn (User $viewer, string $id): ?array => app(CommentDetails::class)->find((int) $viewer->id, $id);
});

it('opens a comment\'s details for whoever looks after its item, the address and browser for editors and administrators alone (D13, D14)', function (): void {
    $comment = cmComment($this->hers, 'pending', attributes: [
        'ip' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 (Test)',
        'author_url' => 'https://ada.example',
        'moderation' => ['by' => 'Teme', 'status' => 'pending', 'reason' => 'Held: a first comment, or the first from this browser.', 'signals' => ['rule' => 'first-comment']],
    ]);
    // Every reply counts, whatever its status (I-577, B-367): the confirmation to delete says what will happen.
    cmComment($this->hers, 'approved', $comment);
    cmComment($this->hers, 'spam', $comment);

    $forEditor = ($this->find)($this->editor, $comment->id);
    $forAuthor = ($this->find)($this->author, $comment->id);

    expect($forEditor['mine'] ?? null)->toBeTrue()
        ->and(array_keys($forEditor['details'] ?? []))->toBe(['id', 'kind', 'status', 'statusLabel', 'authorName', 'authorEmail', 'authorUrl', 'signedIn', 'item', 'parent', 'replies', 'createdAt', 'ip', 'userAgent', 'writtenInAdmin', 'erasesAt', 'keptDays', 'legacyId', 'text', 'plain', 'judgement'])
        ->and($forEditor['details'] ?? [])->toMatchArray([
            'id' => $comment->id,
            'kind' => 'comment',
            'status' => 'pending',
            'statusLabel' => 'Pending',
            'authorName' => 'Ada Guest',
            'authorEmail' => 'ada@example.com',
            'authorUrl' => 'https://ada.example',
            'signedIn' => false,
            'item' => ['id' => $this->hers->id, 'title' => 'Harbour news', 'editable' => true],
            'parent' => null,
            'replies' => 2,
            'ip' => '203.0.113.7',
            'userAgent' => 'Mozilla/5.0 (Test)',
            'legacyId' => null,
            'text' => 'Lovely piece.',
        ])
        ->and($forEditor['details']['judgement']['verdict'] ?? null)->toBe('Held: a first comment, or the first from this browser.')
        ->and($forAuthor['details'] ?? [])->toMatchArray(['ip' => null, 'userAgent' => null, 'authorEmail' => 'ada@example.com']);
});

it('refuses a comment on an item the person doesn\'t look after, naming the item alone (C7, D15)', function (): void {
    $comment = cmComment($this->his, 'pending');

    expect(($this->find)($this->author, $comment->id))->toBe(['mine' => false, 'item' => ['id' => $this->his->id, 'title' => 'Market day', 'singular' => 'post', 'owner' => 'Eze Editor']]);
});

it('opens nothing for a comment that isn\'t there, a tombstone, or one on an item in the trash (D13, D3)', function (): void {
    $tombstone = cmComment($this->his, 'removed', attributes: ['author_name' => null, 'author_email' => null, 'body' => null]);
    $trashed = cmComment($this->hers, 'pending');
    pageTrash($this->hers, $this->author);

    expect(($this->find)($this->editor, cmId()))->toBeNull()
        ->and(($this->find)($this->editor, $tombstone->id))->toBeNull()
        ->and(($this->find)($this->editor, $trashed->id))->toBeNull();
});

it('shows the checks core\'s moderator ran, in its order, the deciding one last (question 3, D16)', function (array $signals, array $rows): void {
    $judgement = CommentVerdict::judgement(
        ['by' => 'Teme', 'status' => 'pending', 'reason' => 'The stored reason.', 'signals' => $signals],
        CommentText::toDocument('See https://a.example and https://b.example today.'),
        null,
        'Ada Guest',
        'post',
    );

    expect(array_map(fn (array $check): string => "{$check['label']} | {$check['state']} | {$check['detail']}", $judgement['checks']))->toBe($rows)
        ->and($judgement['byline'])->toBe('Judged as it arrived, by Teme’s built-in moderator. A spam plugin can take its place.')
        ->and($judgement['verdict'])->toBe('The stored reason.')
        ->and($judgement['hint'])->toBe('Checked in this order. The first that applies decides.');
})->with([
    'the block list' => [['rule' => 'block-list', 'match' => 'casino', 'field' => 'website'], [
        'Block list | bad | “casino”, in the website',
    ]],
    'the hold list' => [['rule' => 'hold-list', 'match' => 'loan', 'field' => 'text'], [
        'Block list | ok | No match',
        'Hold list | warn | “loan”, in the comment',
    ]],
    'links' => [['rule' => 'links', 'links' => 3], [
        'Block list | ok | No match',
        'Hold list | ok | No match',
        'Links | warn | 3 links, enough to hold it',
    ]],
    'hold every comment' => [['rule' => 'hold-all'], [
        'Block list | ok | No match',
        'Hold list | ok | No match',
        'Links | ok | 2 links',
        'Hold every comment | warn | On',
    ]],
    'a returning commenter' => [['rule' => 'returning'], [
        'Block list | ok | No match',
        'Hold list | ok | No match',
        'Links | ok | 2 links',
        'Hold every comment | ok | Off',
        'Returning commenter | ok | Yes: an approved comment from this email address and this browser',
    ]],
    'a first comment' => [['rule' => 'first-comment'], [
        'Block list | ok | No match',
        'Hold list | ok | No match',
        'Links | ok | 2 links',
        'Hold every comment | ok | Off',
        'Returning commenter | warn | No: a first comment, or the first from this browser',
    ]],
    'every check passed' => [['rule' => 'passed'], [
        'Block list | ok | No match',
        'Hold list | ok | No match',
        'Links | ok | 2 links',
        'Hold every comment | ok | Off',
    ]],
]);

it('names whoever looks after the item for a comment approved at once, and says when no judgement was made (D16)', function (): void {
    $own = CommentVerdict::judgement(['by' => 'Teme', 'status' => 'approved', 'reason' => 'Approved at once: whoever looks after this item’s comments.', 'signals' => ['rule' => 'looks-after']], cmBody('Thanks, all.'), null, 'Ama Author', 'page');
    $plugin = CommentVerdict::judgement(['by' => 'Akismet', 'status' => 'spam', 'reason' => 'Akismet says this is spam.', 'signals' => ['score' => 0.98]], cmBody('Buy now.'), null, 'Spammer', 'post');
    $imported = CommentVerdict::judgement(null, cmBody('Old words.'), 123, 'Ada Guest', 'post');
    $none = CommentVerdict::judgement(null, cmBody('Old words.'), null, 'Ada Guest', 'post');

    expect($own['checks'])->toBe([['label' => 'Signed in', 'state' => 'ok', 'detail' => 'Ama Author, who looks after this page’s comments']])
        ->and($own['hint'])->toBeNull()
        ->and([$plugin['byline'], $plugin['verdict'], $plugin['checks'], $plugin['hint']])->toBe(['Judged as it arrived, by Akismet.', 'Akismet says this is spam.', [], null])
        ->and([$imported['byline'], $imported['verdict'], $imported['checks']])->toBe([null, 'Imported from WordPress with its status, so Teme made no judgement.', []])
        ->and($none['verdict'])->toBe('No judgement was recorded.');
});

it('says when a comment\'s address and browser go: keep_ip_days after it arrived (D14, I-270)', function (): void {
    cmSettings(['keep_ip_days' => 30]);
    $comment = cmComment($this->hers, 'pending', attributes: ['ip' => '203.0.113.7', 'created_at' => '2026-10-01 09:00:00+00']);

    expect(($this->find)($this->editor, $comment->id)['details'] ?? [])->toMatchArray([
        'erasesAt' => '2026-10-31T09:00:00+00:00',
        'keptDays' => 30,
    ]);
});
