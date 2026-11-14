<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = cmItem($this->editor);
    $this->thread = 'comments:'.$this->item->id;
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('names an item\'s comments a list, which a change sets aside, and a comment a thing, which a change removes (D5)', function (): void {
    expect(PageTags::isList(PageTags::comments($this->item->id)))->toBeTrue()
        ->and(PageTags::isList(PageTags::comment(cmId())))->toBeFalse();
});

it('sets the thread\'s pages aside when a comment arrives approved, and leaves them when one waits (I-276)', function (): void {
    cacheShown(['/harbour-news/' => [$this->thread], '/elsewhere/' => ['content:unrelated']]);

    cmSubmit($this->item);

    expect(cacheKept('/harbour-news/', '/elsewhere/'))->toHaveCount(2);

    cmSubmit($this->item, 'The tide tables are updated.', ['actorId' => (string) $this->editor->id, 'commenterToken' => null]);

    expect(cacheKept('/harbour-news/', '/elsewhere/'))->toBe(['/elsewhere/'])
        ->and(cacheSetAside('/harbour-news/'))->not->toBeNull();
});

it('removes the page that shows a comment taken down, never serving it stale, and sets the thread\'s other pages aside (D5, I-276)', function (): void {
    $comment = cmComment($this->item);
    cacheShown([
        '/harbour-news/' => [$this->thread, 'comment:'.$comment->id],
        '/harbour-news/comments/page/2/' => [$this->thread],
    ]);

    cmModerate($comment, 'spam', $this->editor);

    expect(cacheKept('/harbour-news/', '/harbour-news/comments/page/2/'))->toBe([])
        ->and(cacheSetAside('/harbour-news/'))->toBeNull()
        ->and(cacheSetAside('/harbour-news/comments/page/2/'))->not->toBeNull();
});

it('purges nothing when a comment moves between statuses no reader sees, or when the move is rolled back (rule 6)', function (): void {
    $comment = cmComment($this->item, 'pending');
    cacheShown(['/harbour-news/' => [$this->thread]]);

    cmModerate($comment, 'spam', $this->editor);
    cmModerate($comment, 'trash', $this->editor);
    cacheRolledBack(fn () => cmModerate($comment, 'approved', $this->editor));

    expect(cacheKept('/harbour-news/'))->toBe(['/harbour-news/']);
});

it('sets the thread aside when a comment deleted for good leaves a tombstone, and removes the pages showing a tombstone that goes (D5, I-577)', function (): void {
    $top = cmComment($this->item);
    $reply = cmComment($this->item, parent: $top);
    cmModerate($top, 'trash', $this->editor);
    cacheShown(['/harbour-news/' => [$this->thread]]);

    expect(cmDelete($top->refresh(), $this->editor))->toBe('removed')
        ->and(cacheSetAside('/harbour-news/'))->not->toBeNull();

    cmModerate($reply, 'trash', $this->editor);
    cacheShown(['/harbour-news/' => [$this->thread, 'comment:'.$top->id]]);

    expect(cmDelete($reply->refresh(), $this->editor))->toBe('deleted')
        ->and(cacheKept('/harbour-news/'))->toBe([])
        ->and(cacheSetAside('/harbour-news/'))->toBeNull();
});

it('purges nothing when a comment in the trash is deleted for good and nothing shown goes with it', function (): void {
    $comment = cmComment($this->item);
    cmModerate($comment, 'trash', $this->editor);
    cacheShown(['/harbour-news/' => [$this->thread]]);

    cmDelete($comment->refresh(), $this->editor);

    expect(cacheKept('/harbour-news/'))->toBe(['/harbour-news/']);
});

it('removes the pages that show an approved comment once its name, website or words are edited, and none for a held one or an email address (D9, rule 6)', function (): void {
    $shown = cmComment($this->item);
    $held = cmComment($this->item, 'pending');
    cacheShown(['/harbour-news/' => [$this->thread, 'comment:'.$shown->id]]);

    cmEdit($held, $this->editor, ['text' => 'Better words.']);
    cmEdit($shown, $this->editor, ['email' => 'ada.new@example.com']);

    expect(cacheKept('/harbour-news/'))->toBe(['/harbour-news/']);

    cmEdit($shown, $this->editor, ['text' => 'Better words.']);

    expect(cacheKept('/harbour-news/'))->toBe([])
        ->and(cacheSetAside('/harbour-news/'))->toBeNull();
});

it('sets the thread\'s pages aside once a reply written in the admin commits, and never after a rollback (D11, rule 6)', function (): void {
    $answered = cmComment($this->item);
    cacheShown(['/harbour-news/' => [$this->thread], '/elsewhere/' => ['content:unrelated']]);

    cacheRolledBack(fn () => cmReply($answered, $this->editor));

    expect(cacheKept('/harbour-news/', '/elsewhere/'))->toHaveCount(2);

    cmReply($answered, $this->editor);

    expect(cacheKept('/harbour-news/', '/elsewhere/'))->toBe(['/elsewhere/'])
        ->and(cacheSetAside('/harbour-news/'))->not->toBeNull();
});
