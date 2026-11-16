<?php

declare(strict_types=1);

use App\Domain\Comments\CommentStats;
use App\Domain\Comments\CommentText;
use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Domain\Fediverse\Jobs\ProcessInboxActivity;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Publishing\ContentActivities;
use App\Domain\Fediverse\Queries\FediverseDocuments;
use App\Domain\Fediverse\Remote\RemoteFetcher;
use App\Domain\Fediverse\Replies\Operations\Input\FediverseReplyInput;
use App\Domain\Fediverse\Replies\Operations\ReceiveFediverseReply;
use App\Domain\Fediverse\Replies\Operations\RemoveFediverseReply;
use App\Domain\Fediverse\Replies\Operations\UpdateFediverseReply;
use App\Domain\Fediverse\Signatures\VerifiedSender;
use App\Domain\Hooks\HookDispatcher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Fediverse\Inbox;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('editor');
    Actors::blog();
    Inbox::switch(true);
    ContentActivities::listen(app(HookDispatcher::class));
    $this->alice = Actors::remote(Inbox::ALICE, ['profile_url' => 'https://remote.example/@alice']);
    $this->item = addrPublished($this->author, 'Harbour news');
    $this->post = FediverseObject::query()->where('content_id', $this->item->id)->value('uri');
});

/**
 * Runs an activity from the account through the inbox's job, as the worker does once its signature is verified.
 *
 * @param  array<string, mixed>  $activity
 */
function frepRun(array $activity, string $from = Inbox::ALICE): void
{
    (new ProcessInboxActivity($activity, $from.'#main-key', $from))->handle(app(FediverseDocuments::class), app(RemoteFetcher::class));
}

/**
 * A Create of a Note answering $inReplyTo, written by $from.
 *
 * @return array<string, mixed>
 */
function frepCreate(string $inReplyTo, string $id, string $html = '<p>Lovely harbour.</p>', string $from = Inbox::ALICE): array
{
    return [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $id.'/activity',
        'type' => 'Create',
        'actor' => $from,
        'object' => ['id' => $id, 'type' => 'Note', 'attributedTo' => $from, 'inReplyTo' => $inReplyTo, 'content' => $html],
    ];
}

it('takes a reply to a post as a comment through the moderation queue, named from the remote account', function (): void {
    frepRun(frepCreate($this->post, 'https://remote.example/statuses/1'));

    $comment = Comment::query()->sole();
    $link = FediverseObject::query()->whereKey('https://remote.example/statuses/1')->sole();

    expect([$comment->content_id, $comment->parent_id, $comment->status])->toBe([$this->item->id, null, 'pending'])
        ->and([$comment->author_name, $comment->author_url, $comment->author_email, $comment->ip])->toBe(['Alice', 'https://remote.example/@alice', null, null])
        ->and(CommentText::plain($comment->body))->toBe('Lovely harbour.')
        ->and([$link->kind, $link->comment_id, $link->content_id, $link->remote_actor_id])->toBe(['reply', $comment->id, $this->item->id, $this->alice->id]);
});

it('takes a reply to a reply under the comment it answers', function (): void {
    frepRun(frepCreate($this->post, 'https://remote.example/statuses/1'));
    $held = Comment::query()->sole();
    $held->forceFill(['status' => 'approved'])->save();
    CommentStats::apply($held, 'pending', 'approved');
    frepRun(frepCreate('https://remote.example/statuses/1', 'https://remote.example/statuses/2', '<p>Agreed.</p>'));

    $first = FediverseObject::query()->whereKey('https://remote.example/statuses/1')->value('comment_id');
    $second = Comment::query()->whereKey(FediverseObject::query()->whereKey('https://remote.example/statuses/2')->value('comment_id'))->sole();

    expect($second->parent_id)->toBe($first)->and($second->content_id)->toBe($this->item->id);
});

it('changes a reply’s words when it is edited, and moves it to the trash when it is deleted', function (): void {
    frepRun(frepCreate($this->post, 'https://remote.example/statuses/1'));
    $update = frepCreate($this->post, 'https://remote.example/statuses/1', '<p>Lovely harbour, again.</p>');
    $update['type'] = 'Update';
    frepRun($update);

    expect(CommentText::plain(Comment::query()->sole()->body))->toBe('Lovely harbour, again.');

    frepRun(['id' => 'https://remote.example/statuses/1#delete', 'type' => 'Delete', 'actor' => Inbox::ALICE, 'object' => ['id' => 'https://remote.example/statuses/1', 'type' => 'Tombstone']]);

    expect([Comment::query()->sole()->status, Comment::query()->sole()->previous_status])->toBe(['trash', 'pending'])
        ->and(FediverseObject::query()->whereKey('https://remote.example/statuses/1')->value('deleted_at'))->not->toBeNull();
});

it('lets no other account change or delete a reply', function (): void {
    frepRun(frepCreate($this->post, 'https://remote.example/statuses/1'));
    $mallory = 'https://remote.example/users/mallory';
    Actors::remote($mallory);
    $update = frepCreate($this->post, 'https://remote.example/statuses/1', '<p>Changed.</p>', $mallory);
    $update['type'] = 'Update';

    frepRun($update, $mallory);
    frepRun(['id' => $mallory.'#delete', 'type' => 'Delete', 'actor' => $mallory, 'object' => 'https://remote.example/statuses/1'], $mallory);

    expect(CommentText::plain(Comment::query()->sole()->body))->toBe('Lovely harbour.')
        ->and(Comment::query()->sole()->status)->toBe('pending');
});

it('drops and logs a reply when the item’s comments are closed', function (): void {
    Log::spy();
    Content::query()->whereKey($this->item->id)->update(['comment_status' => 'closed']);

    frepRun(frepCreate($this->post, 'https://remote.example/statuses/1'));

    expect(Comment::query()->count())->toBe(0)->and(FediverseObject::query()->where('kind', 'reply')->count())->toBe(0);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'dropped') && str_contains($context['why'], 'closed'));
});

it('takes at most ten replies an hour from one account', function (): void {
    foreach (range(1, 11) as $n) {
        frepRun(frepCreate($this->post, 'https://remote.example/statuses/'.$n));
    }

    expect(Comment::query()->count())->toBe(10);

    $this->travel(61)->minutes();
    frepRun(frepCreate($this->post, 'https://remote.example/statuses/12'));

    expect(Comment::query()->count())->toBe(11);
});

it('keeps a reply’s words and drops its script, never storing HTML', function (): void {
    frepRun(frepCreate($this->post, 'https://remote.example/statuses/1', '<p>Hi <script>alert("x")</script><strong>there</strong> <img src="https://remote.example/a.png" onerror="alert(1)"></p>'));

    $body = Comment::query()->sole()->body;

    expect(CommentText::plain($body))->toBe('Hi there')
        ->and(json_encode($body))->not->toContain('script')
        ->and(json_encode($body))->not->toContain('alert')
        ->and(json_encode($body))->not->toContain('<');
});

it('takes a reply only from the account that wrote it, writing nothing otherwise', function (string $operation): void {
    frepRun(frepCreate($this->post, 'https://remote.example/statuses/1'));
    $admin = User::factory()->create()->assignRole('administrator');
    $before = [Comment::query()->count(), FediverseObject::query()->count(), Comment::query()->sole()->status, Comment::query()->sole()->body];
    $object = frepCreate($this->post, $operation === ReceiveFediverseReply::class ? 'https://remote.example/statuses/9' : 'https://remote.example/statuses/1', '<p>Not mine.</p>')['object'];
    $mallory = new VerifiedSender('https://remote.example/users/mallory#main-key', 'https://remote.example/users/mallory');

    foreach ([new FediverseReplyInput($this->alice->id, $object, $mallory), new FediverseReplyInput($this->alice->id, $object), new FediverseReplyInput($this->alice->id, $object, actorId: (string) $admin->id)] as $input) {
        expect(fn () => app($operation)->execute($input))->toThrow(AuthorizationException::class);
    }

    expect([Comment::query()->count(), FediverseObject::query()->count(), Comment::query()->sole()->status, Comment::query()->sole()->body])->toBe($before)
        ->and(RemoteActor::query()->count())->toBe(1);
})->with([ReceiveFediverseReply::class, UpdateFediverseReply::class, RemoveFediverseReply::class]);

it('holds an approved reply for moderation again when an edit adds a link, and keeps it shown when the words alone change', function (): void {
    frepRun(frepCreate($this->post, 'https://remote.example/statuses/1'));
    $held = Comment::query()->sole();
    $held->forceFill(['status' => 'approved'])->save();
    CommentStats::apply($held, 'pending', 'approved');
    $update = fn (string $html): array => [...frepCreate($this->post, 'https://remote.example/statuses/1', $html), 'type' => 'Update'];

    frepRun($update('<p>Lovely harbour, truly.</p>'));
    $plain = Comment::query()->sole()->status;
    frepRun($update('<p>Cheap boats at <a href="https://spam.example/">spam.example</a></p>'));
    $comment = Comment::query()->sole();

    expect($plain)->toBe('approved')
        ->and($comment->status)->toBe('pending')
        ->and(CommentText::plain($comment->body))->toContain('Cheap boats')
        ->and($comment->moderation['status'] ?? null)->toBe('pending');
});

it('counts edits toward the ten replies an hour one account may send', function (): void {
    frepRun(frepCreate($this->post, 'https://remote.example/statuses/1'));

    foreach (range(1, 10) as $n) {
        frepRun([...frepCreate($this->post, 'https://remote.example/statuses/1', '<p>Edit '.$n.'.</p>'), 'type' => 'Update']);
    }

    expect(CommentText::plain(Comment::query()->sole()->body))->toBe('Edit 9.');
});
