<?php

declare(strict_types=1);

use App\Domain\Comments\CommentText;
use App\Domain\Comments\Models\Comment;
use App\Domain\Comments\Queries\CommentDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ama Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->hers = cmItem($this->author, 'Harbour news');
    $this->his = cmItem($this->editor, 'Market day');
    $this->bold = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Lovely '],
        ['type' => 'text', 'text' => 'harbour', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => ' piece.'],
    ]]]];
});

it('edits the name, email address and website, and keeps the stored document while the text is unchanged (C3, D9)', function (): void {
    $comment = cmComment($this->hers, 'approved', attributes: ['body' => $this->bold]);

    $edited = cmEdit($comment, $this->author, ['name' => '  Ada Okafor ', 'email' => 'ADA@Example.com', 'url' => 'ada.example']);

    $comment->refresh();

    expect([$edited->commentId, $edited->authorName, $edited->madePlain])->toBe([$comment->id, 'Ada Okafor', false])
        ->and([$comment->author_name, $comment->author_email, $comment->author_url])->toBe(['Ada Okafor', 'ada@example.com', 'https://ada.example'])
        ->and($comment->body)->toEqual($this->bold);
});

it('replaces the document with plain text when the text changes, and says when formatting went with it (C3, D9)', function (): void {
    $bold = cmComment($this->hers, 'approved', attributes: ['body' => $this->bold]);
    $plain = cmComment($this->hers);

    $edited = cmEdit($bold, $this->author, ['text' => "Lovely piece.\r\n\r\nSee https://tides.example today."]);

    expect($edited->madePlain)->toBeTrue()
        ->and($bold->refresh()->body)->toEqual(CommentText::toDocument("Lovely piece.\n\nSee https://tides.example today."))
        ->and(cmEdit($plain, $this->author, ['text' => 'Better words.'])->madePlain)->toBeFalse()
        ->and($plain->refresh()->body)->toEqual(cmBody('Better words.'));
});

it('calls a comment plain when its text, made into a document again, gives the one stored, its keys in any order (C3, D10)', function (): void {
    $stored = cmComment($this->hers, 'approved', attributes: ['body' => CommentText::toDocument("Hello\nthere.\n\nhttps://tides.example")]);
    $quote = ['type' => 'doc', 'content' => [['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quoted.']]]]]]];
    $named = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'the tide tables', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://tides.example']]]]]]]];

    expect(CommentText::isPlain($stored->refresh()->body ?? []))->toBeTrue()
        ->and(CommentText::isPlain($this->bold))->toBeFalse()
        ->and(CommentText::isPlain($quote))->toBeFalse()
        ->and(CommentText::isPlain($named))->toBeFalse();
});

it('refuses what Quick edit can\'t save, in its own words, and writes nothing (C3, D17, question 1)', function (array $fields, string $field, string $words): void {
    $comment = cmComment($this->hers);

    expect(fn () => cmEdit($comment, $this->author, $fields))
        ->toThrow(fn (ValidationException $refused) => expect($refused->errors())->toBe([$field => [$words]]))
        ->and($comment->refresh()->only(['author_name', 'author_email', 'author_url']))->toBe(['author_name' => 'Ada Guest', 'author_email' => 'ada@example.com', 'author_url' => null]);
})->with([
    'no name' => [['name' => '  '], 'name', 'Enter the commenter’s name.'],
    'a name too long' => [['name' => str_repeat('a', 101)], 'name', 'Enter a name of 100 characters or fewer.'],
    'an email address that isn\'t one' => [['email' => 'not an address'], 'email', 'Enter an email address, like name@example.com.'],
    'a website that isn\'t one' => [['url' => 'https://'], 'url', 'Enter a web address that starts with https://, or leave this empty.'],
    'no words' => [['text' => "\n \n"], 'text', 'Write the comment’s text, or move it to the trash.'],
    'too many words' => [['text' => str_repeat('a', 5001)], 'text', 'Shorten the comment to 5,000 characters or fewer. It has 5,001.'],
]);

it('never edits a ping or a tombstone (C8, I-278)', function (): void {
    $ping = cmComment($this->hers, 'approved', attributes: ['kind' => 'pingback', 'author_name' => 'Harbour Weekly', 'author_email' => null, 'author_url' => 'https://weekly.example/post']);
    $tombstone = cmComment($this->hers, 'removed', attributes: ['author_name' => null, 'author_email' => null, 'body' => null]);

    expect(fn () => cmEdit($ping, $this->author, ['email' => 'weekly@example.com']))->toThrow(ValidationException::class, 'Pingbacks and trackbacks can’t be edited: their words are another site’s.')
        ->and(fn () => cmEdit($tombstone, $this->author, ['name' => 'Ada Guest', 'email' => 'ada@example.com', 'text' => 'Back again.']))->toThrow(ValidationException::class, 'A removed comment can’t be edited.')
        ->and($ping->refresh()->author_email)->toBeNull()
        ->and($tombstone->refresh()->author_name)->toBeNull();
});

it('writes nothing for someone who doesn\'t look after the item\'s comments (authorization)', function (?string $role): void {
    $comment = cmComment($this->his);
    $person = $role === null ? $this->author : User::factory()->create()->assignRole($role);

    expect(fn () => cmEdit($comment, $person, ['name' => 'Someone Else']))->toThrow(AuthorizationException::class)
        ->and($comment->refresh()->author_name)->toBe('Ada Guest');
})->with([
    'another item\'s author' => [null],
    'a contributor' => ['contributor'],
]);

it('saves from Quick edit, says so, and answers to the list as it was, without the drawer (D13)', function (): void {
    $plain = cmNext($this->hers, attributes: ['author_name' => 'Bola Ade']);
    $bold = cmNext($this->hers, attributes: ['body' => $this->bold]);
    $save = fn (Comment $comment, array $fields) => $this->actingAs($this->author)
        ->from(route('comments.index', ['view' => 'approved', 'comment' => $comment->id]))
        ->post(route('comments.edit'), [...['id' => $comment->id, 'name' => $comment->author_name, 'email' => $comment->author_email, 'url' => '', 'text' => CommentText::plain($comment->body ?? [])], ...$fields]);

    $save($plain, ['name' => 'Bola Ade.'])
        ->assertRedirect(route('comments.index', ['view' => 'approved']))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Saved the comment by Bola Ade.');

    $save($bold, ['text' => 'Plain words now.'])->assertInertiaFlash('toast.message', 'Saved the comment by Ada Guest. It’s plain text now.');
    $save($bold->refresh(), ['name' => ''])->assertSessionHasErrors(['name' => 'Enter the commenter’s name.']);
});

it('answers a comment that changed under Quick edit with a warning, never an error page (D13, question 1)', function (): void {
    $gone = cmNext($this->hers, 'spam');
    cmDelete($gone, $this->author);
    $theirs = cmNext($this->his);

    foreach ([$gone->id, $theirs->id] as $id) {
        $this->actingAs($this->author)
            ->from(route('comments.index'))
            ->post(route('comments.edit'), ['id' => $id, 'name' => 'Ada Guest', 'email' => 'ada@example.com', 'url' => '', 'text' => 'Lovely piece.'])
            ->assertRedirect(route('comments.index'))
            ->assertInertiaFlash('toast.type', 'warning')
            ->assertInertiaFlash('toast.message', 'Nothing was saved, because the comment had changed since you opened the list.');
    }

    expect($theirs->refresh()->author_name)->toBe('Ada Guest');
});

it('tells each row and the drawer whether a comment is plain, so Quick edit\'s hint is right wherever it opens (D10, B-367)', function (): void {
    $plain = cmNext($this->hers);
    $bold = cmNext($this->hers, attributes: ['body' => $this->bold]);

    expect(array_column(cmList($this->author, ['view' => 'approved'])['rows'], 'plain', 'id'))->toBe([$bold->id => false, $plain->id => true])
        ->and(app(CommentDetails::class)->find((int) $this->author->id, $bold->id)['details']['plain'] ?? null)->toBeFalse();
});
