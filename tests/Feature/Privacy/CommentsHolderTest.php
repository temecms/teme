<?php

declare(strict_types=1);

use App\Domain\Comments\CommentText;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Privacy\CommentsHolder;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyErasureInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyErasure;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\PersonalDataSubject;
use Tests\Fixtures\Import\CommentImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = cmItem($this->editor);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), [CommentsHolder::class]));

    $this->holder = app(CommentsHolder::class);
    $this->erase = function (string $email = 'ada@example.com'): PrivacyRequest {
        $id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('erase', $email, 'Asked by email.', false));

        for ($steps = 0; $steps < 20 && ! app(RunPrivacyErasure::class)->execute(new RunPrivacyErasureInput($id))->done; $steps++);

        return PrivacyRequest::query()->findOrFail($id);
    };
});

it('exports a guest’s comments by address and an account’s by account, each titled with its item, its values in order', function (): void {
    $ada = User::factory()->create(['email' => 'ada@example.com']);
    $guest = cmComment($this->item, 'pending', attributes: ['author_url' => 'https://ada.example', 'ip' => '192.0.2.7', 'user_agent' => 'Firefox', 'created_at' => '2026-11-01 10:00:00+00']);
    $signedIn = cmComment($this->item, attributes: ['author_email' => 'other@example.com', 'author_user_id' => $ada->id, 'author_name' => 'Ada Account']);

    $page = $this->holder->export(new PersonalDataSubject('ada@example.com', (string) $ada->id), 1);
    $site = rtrim((string) config('app.url'), '/');

    expect($page->done)->toBeTrue()
        ->and($page->groups)->toHaveCount(2)
        ->and($page->groups[0]->title)->toBe('Comment on “Harbour news”')
        ->and($page->groups[0]->values)->toBe([
            'Name' => 'Ada Guest',
            'Email' => 'ada@example.com',
            'Website' => 'https://ada.example',
            'Comment' => 'Lovely piece.',
            'Status' => 'Pending',
            'Date' => '2026-11-01T10:00:00+00:00',
            'Address' => $site.'/_teme/comment/'.$guest->id,
            'IP address' => '192.0.2.7',
            'Browser' => 'Firefox',
        ])
        ->and(array_keys($page->groups[1]->values))->toBe(['Name', 'Email', 'Comment', 'Status', 'Date', 'Address'])
        ->and($page->groups[1]->values['Address'])->toBe($site.'/_teme/comment/'.$signedIn->id);
});

it('never exports a tombstone or another person’s comment', function (): void {
    cmComment($this->item, 'approved', attributes: ['author_email' => 'grace@example.com']);
    cmRow($this->item, ['status' => 'removed', 'body' => null, 'author_name' => null, 'author_email' => null]);

    expect($this->holder->export(new PersonalDataSubject('ada@example.com'), 1)->groups)->toBe([]);
});

it('exports 100 a page and says done on the last', function (): void {
    foreach (range(1, 150) as $count) {
        cmRow($this->item);
    }

    $first = $this->holder->export(new PersonalDataSubject('ada@example.com'), 1);
    $second = $this->holder->export(new PersonalDataSubject('ada@example.com'), 2);

    expect($first->groups)->toHaveCount(100)
        ->and($first->done)->toBeFalse()
        ->and($second->groups)->toHaveCount(50)
        ->and($second->done)->toBeTrue();
});

it('anonymises every comment of the address whatever its status, keeping its words, its place and its dates', function (string $status): void {
    $comment = cmComment($this->item, $status, attributes: [
        'author_url' => 'https://ada.example', 'ip' => '192.0.2.7', 'user_agent' => 'Firefox',
        'commenter_token_hash' => str_repeat('c', 64), 'moderation' => ['by' => 'Teme', 'signals' => ['match' => 'ada@example.com']],
    ]);
    $before = DB::table('comments')->where('id', $comment->id)->first();

    $request = ($this->erase)();
    $after = Comment::query()->findOrFail($comment->id);
    $raw = DB::table('comments')->where('id', $comment->id)->first();

    expect($after->only(['author_name', 'author_email', 'author_url', 'author_user_id', 'ip', 'user_agent', 'commenter_token_hash', 'moderation']))->toBe([
        'author_name' => 'Anonymous', 'author_email' => null, 'author_url' => null, 'author_user_id' => null,
        'ip' => null, 'user_agent' => null, 'commenter_token_hash' => null, 'moderation' => null,
    ])
        ->and(CommentText::plain($after->body ?? []))->toBe('Lovely piece.')
        ->and($raw->status)->toBe($before->status)
        ->and($raw->path)->toBe($before->path)
        ->and($raw->updated_at)->toBe($before->updated_at)
        ->and($raw->status_changed_at)->toBe($before->status_changed_at)
        ->and($request->results[0])->toMatchArray(['key' => 'comments', 'removed' => 1, 'retained' => 0, 'done' => true]);
})->with(['approved', 'pending', 'spam', 'trash']);

it('anonymises an account’s comments under another address, and leaves another commenter’s on the same item', function (): void {
    $ada = User::factory()->create(['email' => 'ada@example.com']);
    $signedIn = cmComment($this->item, attributes: ['author_email' => 'ada.old@example.com', 'author_user_id' => $ada->id]);
    $grace = cmComment($this->item, attributes: ['author_name' => 'Grace', 'author_email' => 'grace@example.com']);

    ($this->erase)();

    expect($signedIn->refresh()->author_name)->toBe('Anonymous')
        ->and($signedIn->author_email)->toBeNull()
        ->and($grace->refresh()->only(['author_name', 'author_email']))->toBe(['author_name' => 'Grace', 'author_email' => 'grace@example.com']);
});

it('neither exports nor erases a comment with the address that another account wrote, and keeps it with its reason', function (): void {
    // Account B once commented as ada@example.com, then changed its address.
    $bea = User::factory()->create(['email' => 'bea@example.com']);
    $theirs = cmComment($this->item, attributes: ['author_name' => 'Bea', 'author_user_id' => $bea->id, 'ip' => '198.51.100.4', 'user_agent' => 'Safari']);
    $guest = cmComment($this->item);
    $before = DB::table('comments')->where('id', $theirs->id)->first();

    $page = $this->holder->export(new PersonalDataSubject('ada@example.com', null), 1);
    $request = ($this->erase)();

    expect($page->groups)->toHaveCount(1)
        ->and(json_encode($page->groups))->not->toContain('198.51.100.4')
        ->and(DB::table('comments')->where('id', $theirs->id)->first())->toEqual($before)
        ->and($guest->refresh()->author_name)->toBe('Anonymous')
        ->and($request->results[0])->toMatchArray(['removed' => 1, 'retained' => 1, 'reasons' => ['Some comments with this address belong to another account, so they were kept.']]);
});

it('erases and exports a comment with the address that the subject’s own account wrote', function (): void {
    $ada = User::factory()->create(['email' => 'ada@example.com']);
    $own = cmComment($this->item, attributes: ['author_user_id' => $ada->id]);

    expect($this->holder->export(new PersonalDataSubject('ada@example.com', (string) $ada->id), 1)->groups)->toHaveCount(1);

    $request = ($this->erase)();

    expect($own->refresh()->author_name)->toBe('Anonymous')
        ->and($request->results[0])->toMatchArray(['removed' => 1, 'retained' => 0, 'reasons' => []]);
});

it('takes 450 comments in three pages, the third done', function (): void {
    foreach (range(1, 450) as $count) {
        cmRow($this->item);
    }

    $request = ($this->erase)();

    expect($request->results[0])->toMatchArray(['page' => 3, 'removed' => 450, 'done' => true])
        ->and(DB::table('comments')->where('author_email', 'ada@example.com')->count())->toBe(0);
});

it('purges an approved comment’s pages after commit, and not a spam one’s, nor anything on a rollback (rule 6)', function (): void {
    $folder = cacheOn();
    $approved = cmComment($this->item);
    $spam = cmComment(cmItem($this->editor, 'Tide tables'), 'spam');
    cacheShown([
        '/harbour-news/' => ['comments:'.$this->item->id, 'comment:'.$approved->id],
        '/tide-tables/' => ['comments:'.$spam->content_id],
    ]);

    cacheRolledBack(fn () => ($this->erase)());

    expect(cacheKept('/harbour-news/', '/tide-tables/'))->toBe(['/harbour-news/', '/tide-tables/']);

    ($this->erase)();

    expect(cacheKept('/harbour-news/', '/tide-tables/'))->toBe(['/tide-tables/']);

    cacheOff($folder);
});

it('keeps an imported comment erased when the import meets it again', function (): void {
    $companion = importFake();
    $source = importSource(User::factory()->create()->assignRole('administrator'));
    CommentImports::post($source, 20, $this->item, '/harbour-news/');
    CommentImports::run($companion, $source, [CommentImports::item(30, ['status' => '0'])]);
    $this->travel(1)->hours();

    ($this->erase)();
    CommentImports::run($companion, $source, [CommentImports::item(30, ['status' => '1', 'content' => 'Lovely piece, truly.'])]);
    $comment = CommentImports::comment($source, 30);

    expect($comment->author_name)->toBe('Anonymous')
        ->and($comment->author_email)->toBeNull()
        ->and($comment->author_url)->toBeNull()
        ->and(CommentImports::codes($source, 30))->toBe(['comment.changed-here']);
});
