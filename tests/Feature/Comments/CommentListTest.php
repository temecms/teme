<?php

declare(strict_types=1);

use App\Domain\Comments\Queries\CommentList;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->hers = cmItem($this->author, 'Harbour news');
    $this->his = cmItem($this->editor, 'Market day');
    $this->ids = fn (User $viewer, array $request): array => array_column(cmList($viewer, $request)['rows'], 'id');
    $this->counts = fn (User $viewer, ?string $item = null): array => array_column(app(CommentList::class)->counts((int) $viewer->id, $item), 'count', 'key');
});

it('lists a view newest first, a page at a time, and pages back and forth by keyset (D6)', function (): void {
    $made = array_map(fn (int $n): string => cmNext($this->his, 'pending')->id, range(1, 30));
    $newestFirst = array_reverse($made);

    $first = cmList($this->editor, ['perPage' => 25]);
    $second = cmList($this->editor, ['perPage' => 25, 'after' => $first['paging']['older']]);
    $back = cmList($this->editor, ['perPage' => 25, 'before' => $second['paging']['newer']]);

    expect(array_column($first['rows'], 'id'))->toBe(array_slice($newestFirst, 0, 25))
        ->and($first['paging']['newer'])->toBeNull()
        ->and(array_column($second['rows'], 'id'))->toBe(array_slice($newestFirst, 25))
        ->and($second['paging']['older'])->toBeNull()
        ->and(array_column($back['rows'], 'id'))->toBe(array_slice($newestFirst, 0, 25));
});

it('lists the first page for a before cursor it cannot decode, not the oldest rows (a hand-edited link)', function (): void {
    array_map(fn (int $n): string => cmNext($this->his, 'pending')->id, range(1, 30));

    $first = cmList($this->editor, ['perPage' => 25]);
    $edited = cmList($this->editor, ['perPage' => 25, 'before' => 'not-a-cursor']);

    expect(array_column($edited['rows'], 'id'))->toBe(array_column($first['rows'], 'id'))
        ->and($edited['paging']['newer'])->toBeNull()
        ->and($edited['paging']['older'])->toBe($first['paging']['older']);
});

it('shows All as pending and approved, each status in a view of its own, never a tombstone, and Pending for a view that isn\'t one (D4, C10)', function (): void {
    $pending = cmNext($this->his, 'pending');
    $approved = cmNext($this->his);
    $spam = cmNext($this->his, 'spam');
    $trash = cmNext($this->his, 'trash');
    cmNext($this->his, 'removed', attributes: ['author_name' => null, 'author_email' => null, 'body' => null]);

    expect(($this->ids)($this->editor, ['view' => 'all']))->toBe([$approved->id, $pending->id])
        ->and(($this->ids)($this->editor, ['view' => 'pending']))->toBe([$pending->id])
        ->and(($this->ids)($this->editor, ['view' => 'approved']))->toBe([$approved->id])
        ->and(($this->ids)($this->editor, ['view' => 'spam']))->toBe([$spam->id])
        ->and(($this->ids)($this->editor, ['view' => 'trash']))->toBe([$trash->id])
        ->and(cmList($this->editor, ['view' => 'removed']))->toMatchArray(['view' => 'pending', 'rows' => cmList($this->editor)['rows']])
        ->and(app(CommentList::class)->views())->toBe(['all', 'pending', 'approved', 'spam', 'trash']);
});

it('narrows the list to one item (D13)', function (): void {
    $theirs = cmNext($this->hers, 'pending');
    cmNext($this->his, 'pending');

    expect(($this->ids)($this->editor, ['itemId' => $this->hers->id]))->toBe([$theirs->id]);
});

it('lists an author the comments on their own items alone, and an editor everyone\'s (C1, D2)', function (): void {
    $theirs = cmNext($this->hers, 'pending');
    $others = cmNext($this->his, 'pending');

    expect(($this->ids)($this->author, []))->toBe([$theirs->id])
        ->and(($this->ids)($this->editor, []))->toBe([$others->id, $theirs->id])
        ->and(($this->ids)($this->author, ['itemId' => $this->his->id]))->toBe([]);
});

it('leaves out the comments on an item in the trash, from its lists and its counts (D3)', function (): void {
    cmNext($this->his, 'pending');
    cmNext($this->his, 'spam');
    $kept = cmNext($this->hers, 'pending');
    pageTrash($this->his, $this->editor);

    expect(($this->ids)($this->editor, []))->toBe([$kept->id])
        ->and(($this->ids)($this->editor, ['view' => 'spam']))->toBe([])
        ->and(($this->counts)($this->editor))->toBe(['all' => 1, 'pending' => 1, 'approved' => 0, 'spam' => 0, 'trash' => 0]);
});

it('searches the words readers see inside the document, never its keys, and takes % and _ as typed (D7)', function (): void {
    $rich = cmNext($this->his, attributes: ['body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Lovely '],
        ['type' => 'text', 'text' => 'harbour', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => ' views at '],
        ['type' => 'text', 'text' => 'example.org', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.org/views']]]],
    ]]]]]);
    $percent = cmNext($this->his, attributes: ['body' => cmBody('I agree 100% with this.')]);
    cmNext($this->his, attributes: ['body' => cmBody('I agree 1000 times over.')]);
    $found = fn (string $search): array => ($this->ids)($this->editor, ['view' => 'all', 'search' => $search]);

    expect($found('LOVELY HARBOUR views'))->toBe([$rich->id])
        ->and($found('paragraph'))->toBe([])
        ->and($found('href'))->toBe([])
        ->and($found('example.org/views'))->toBe([])
        ->and($found('100%'))->toBe([$percent->id]);
});

it('searches the name, the email address and the website (D7)', function (string $search): void {
    $match = cmNext($this->his, attributes: ['author_name' => 'Bola Ade', 'author_email' => 'bola@harbour.example', 'author_url' => 'https://bola.example/blog']);
    cmNext($this->his);

    expect(($this->ids)($this->editor, ['view' => 'all', 'search' => $search]))->toBe([$match->id]);
})->with([
    'the name' => ['bola ade'],
    'the email address' => ['BOLA@HARBOUR'],
    'the website' => ['bola.example/blog'],
]);

it('searches IP addresses for editors and administrators alone, and gives no one else an address (I-270 B, D14)', function (): void {
    cmNext($this->hers, 'pending', attributes: ['ip' => '203.0.113.7']);

    expect(array_column(cmList($this->editor, ['search' => '203.0.113'])['rows'], 'ip'))->toBe(['203.0.113.7'])
        ->and(cmList($this->author, ['search' => '203.0.113'])['rows'])->toBe([])
        ->and(array_column(cmList($this->author)['rows'], 'ip'))->toBe([null]);
});

it('counts each view from the maintained counts and the parked comments, for the items a person looks after (C10, D5)', function (): void {
    cmNext($this->hers, 'pending');
    cmNext($this->hers);
    cmNext($this->hers);
    cmNext($this->hers, 'spam');
    cmNext($this->his, 'pending');
    cmNext($this->his, 'trash');
    cmNext($this->his, 'trash');

    expect(($this->counts)($this->editor))->toBe(['all' => 4, 'pending' => 2, 'approved' => 2, 'spam' => 1, 'trash' => 2])
        ->and(($this->counts)($this->author))->toBe(['all' => 3, 'pending' => 1, 'approved' => 2, 'spam' => 1, 'trash' => 0])
        ->and(($this->counts)($this->editor, $this->his->id))->toBe(['all' => 1, 'pending' => 1, 'approved' => 0, 'spam' => 0, 'trash' => 2])
        ->and(array_column(app(CommentList::class)->counts((int) $this->editor->id), 'label'))->toBe(['All', 'Pending', 'Approved', 'Spam', 'Trash']);
});

it('gives each row what the screen draws, the item it\'s on and the comment it answers (D14)', function (): void {
    cmNext($this->hers, 'pending');
    $answered = cmNext($this->his, attributes: ['author_name' => 'Bola Ade']);
    $reply = cmNext($this->his, 'pending', $answered, ['author_user_id' => $this->author->id, 'author_name' => 'Ama Author', 'author_email' => 'ama@example.com']);
    $moderator = User::factory()->create()->givePermissionTo('comments.moderate');

    $row = cmList($this->editor)['rows'][0];

    expect(array_keys($row))->toBe(['id', 'kind', 'status', 'parentId', 'authorName', 'authorEmail', 'authorUrl', 'signedIn', 'ip', 'text', 'parent', 'item', 'createdAt', 'replies', 'plain'])
        ->and($row)->toMatchArray([
            'id' => $reply->id,
            'kind' => 'comment',
            'status' => 'pending',
            'parentId' => $answered->id,
            'authorName' => 'Ama Author',
            'authorEmail' => 'ama@example.com',
            'authorUrl' => null,
            'signedIn' => true,
            'ip' => null,
            'text' => 'Lovely piece.',
            'parent' => ['authorName' => 'Bola Ade', 'removed' => false],
            'item' => ['id' => $this->his->id, 'title' => 'Market day', 'editable' => true],
            'createdAt' => now()->utc()->toIso8601String(),
            'replies' => 0,
        ])
        ->and(cmList($this->editor, ['view' => 'approved'])['rows'][0]['replies'])->toBe(1)
        ->and(cmList($this->author)['rows'][0]['item']['editable'])->toBeTrue()
        ->and(cmList($moderator)['rows'][0]['item']['editable'])->toBeFalse();
});

it('lists imported pings as comments, with their kind, and searches them (C8, D17)', function (): void {
    $ping = cmNext($this->his, attributes: ['kind' => 'pingback', 'author_name' => 'Harbour Weekly', 'author_email' => null, 'author_url' => 'https://weekly.example/post']);

    $row = cmList($this->editor, ['view' => 'approved'])['rows'][0];

    expect([$row['id'], $row['kind'], $row['authorEmail'], $row['authorUrl']])->toBe([$ping->id, 'pingback', null, 'https://weekly.example/post'])
        ->and(($this->ids)($this->editor, ['view' => 'all', 'search' => 'weekly']))->toBe([$ping->id]);
});

it('finds the item a list is narrowed to, and whether the person looks after it (D13, C1)', function (): void {
    $list = app(CommentList::class);

    expect($list->item((int) $this->author->id, $this->his->id))->toBe(['id' => $this->his->id, 'title' => 'Market day', 'singular' => 'post', 'owner' => 'Eze Editor', 'mine' => false])
        ->and($list->item((int) $this->author->id, $this->hers->id)['mine'] ?? null)->toBeTrue()
        ->and($list->item((int) $this->author->id, cmId()))->toBeNull();
});

it('orders comments for deleting deepest first, so a reply goes before the comment it answers (D12)', function (): void {
    $top = cmNext($this->his, 'spam');
    $reply = cmNext($this->his, 'spam', $top);
    $deeper = cmNext($this->his, 'spam', $reply, ['author_name' => 'Bola Ade']);
    $other = cmNext($this->his, 'spam');

    $order = app(CommentList::class)->deletionOrder([$top->id, $other->id, $deeper->id, $reply->id, cmId()]);

    expect(array_column($order, 'id'))->toBe([$deeper->id, $reply->id, $top->id, $other->id])
        ->and($order[0]['authorName'])->toBe('Bola Ade');
});
