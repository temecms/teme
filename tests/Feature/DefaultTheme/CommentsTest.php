<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->item = cmItem($this->author);
    $this->page = fn (string $uri): DOMXPath => dtDom((string) addrGet($uri)->assertOk()->getContent());
});

it('draws the thread as a section headed by its count, conversations and replies in lists core\'s script finds by their hooks (I-595)', function (): void {
    $top = cmComment($this->item, attributes: ['author_name' => 'Bo Reader']);
    cmComment($this->item, parent: $top);
    $dom = ($this->page)('/harbour-news/');

    expect(trim((string) $dom->query('//section[@id="comments"][@aria-labelledby="comments-h"]/h2[@id="comments-h"]')->item(0)?->textContent))->toBe('2 comments')
        ->and($dom->query('//ol[@data-teme-thread][@data-teme-depth="5"]/li[@id="comment-'.$top->id.'"][@data-teme-comment="'.$top->id.'"]/ol[@data-teme-replies]/li[@data-teme-comment]')->length)->toBe(1)
        ->and(trim((string) $dom->query('//*[contains(@class, "c-inreply")]')->item(0)?->textContent))->toBe('In reply to Bo Reader');
});

it('draws an actions line on every comment that isn\'t waiting: Reply inside it while offered, hidden while it holds nothing, named for the writer (I-678, D4)', function (): void {
    $guest = cmComment($this->item, attributes: ['author_name' => 'Ada <b>Guest']);
    $line = '//li[@data-teme-comment="'.$guest->id.'"]/article/p[@data-teme-actions="Ada <b>Guest"]';
    $open = ($this->page)('/harbour-news/');

    expect($open->query($line.'[not(@hidden)]/a[@data-teme-reply="'.$guest->id.'"]')->length)->toBe(1);

    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'closed']);
    $closed = ($this->page)('/harbour-news/');

    expect($closed->query($line.'[@hidden]')->length)->toBe(1)
        ->and($closed->query($line.'/*')->length)->toBe(0)
        ->and($closed->query('//a[@data-teme-reply]')->length)->toBe(0);
});

it('draws no actions line where there is nothing to act on: a tombstone, a ping, a waiting comment, the comment a reply page answers (I-678, D4)', function (): void {
    $gone = cmComment($this->item, 'removed', attributes: ['author_name' => null, 'author_email' => null, 'body' => null]);
    $reply = cmComment($this->item, parent: $gone, attributes: ['author_name' => 'Cy Reader']);
    cmComment($this->item, attributes: ['kind' => 'pingback', 'author_name' => 'Tide Watch', 'author_url' => 'https://tides.example/post', 'author_email' => null]);
    $dom = ($this->page)('/harbour-news/');

    expect($dom->query('//p[@data-teme-actions]')->length)->toBe(1)
        ->and($dom->query('//li[@data-teme-comment="'.$reply->id.'"]/article/p[@data-teme-actions]')->length)->toBe(1);

    // Another name and address than the comments above, so the commenter is a new one and the comment waits (B-355).
    cmSubmit($this->item, 'A first word from the quay.', ['name' => 'Quay Reader', 'email' => 'quay@example.com']);
    $waiting = (string) cmPending('/harbour-news/', 'browser-one-0123456789abcdef')->json('comments.0.html');

    expect($waiting)->toContain('Awaiting moderation.')
        ->and(dtDom('<meta charset="utf-8">'.$waiting)->query('//p[@data-teme-actions]')->length)->toBe(0)
        ->and(($this->page)('/_teme/comment/'.$reply->id.'/reply')->query('//p[@data-teme-actions]')->length)->toBe(0);
});

it('hooks the heading\'s count and the empty line, so core\'s script can recount after a first comment (I-639, I-692, D8)', function (): void {
    $none = ($this->page)('/harbour-news/');

    expect($none->query('//h2[@id="comments-h"][@data-teme-count="0"]')->length)->toBe(1)
        ->and(trim((string) $none->query('//p[@data-teme-empty]')->item(0)?->textContent))->toBe('No comments yet. Start the conversation.');

    $top = cmComment($this->item);
    cmComment($this->item, parent: $top);
    cmComment($this->item, 'pending');
    $some = ($this->page)('/harbour-news/');

    expect($some->query('//h2[@id="comments-h"][@data-teme-count="2"]')->length)->toBe(1)
        ->and($some->query('//*[@data-teme-empty]')->length)->toBe(0);
});

it('marks the story\'s author, links a website as user-generated, the time to the comment\'s address, and Reply to its page (I-597, I-594)', function (): void {
    $theirs = cmComment($this->item, attributes: ['author_name' => 'Jane Doe', 'author_user_id' => $this->author->id]);
    $guests = cmComment($this->item, attributes: ['author_name' => 'Ada Guest', 'author_url' => 'https://ada.example']);
    $dom = ($this->page)('/harbour-news/');

    expect(trim((string) $dom->query('//*[@id="comment-'.$theirs->id.'"]//*[contains(@class, "c-badge")]')->item(0)?->textContent))->toBe('Author')
        ->and($dom->query('//*[@id="comment-'.$guests->id.'"]//*[contains(@class, "c-badge")]')->length)->toBe(0)
        ->and($dom->query('//a[@rel="ugc nofollow"][@href="https://ada.example"][normalize-space(.)="Ada Guest"]')->length)->toBe(1)
        ->and($dom->query('//a[contains(@class, "c-time")][@href="/_teme/comment/'.$guests->id.'"]')->length)->toBe(1)
        ->and($dom->query('//a[@rel="nofollow"][@data-teme-reply="'.$guests->id.'"][@href="/_teme/comment/'.$guests->id.'/reply"]')->length)->toBe(1);
});

it('keeps a tombstone\'s place, draws a ping on one line, and prints an imported comment\'s old anchor (I-277, I-278)', function (): void {
    $gone = cmComment($this->item, 'removed', attributes: ['author_name' => null, 'author_email' => null, 'body' => null]);
    cmComment($this->item, parent: $gone);
    cmComment($this->item, attributes: ['kind' => 'pingback', 'author_name' => 'Tide Watch', 'author_url' => 'https://tides.example/post', 'author_email' => null]);
    cmComment($this->item, attributes: ['legacy_id' => 123]);
    $dom = ($this->page)('/harbour-news/');

    expect(trim((string) $dom->query('//li[@id="comment-'.$gone->id.'"]/*[contains(@class, "c-removed")]')->item(0)?->textContent))->toBe('This comment was removed.')
        ->and(trim((string) $dom->query('//*[contains(@class, "c-ping")]/span')->item(0)?->textContent))->toBe('Linked from Tide Watch')
        ->and($dom->query('//*[contains(@class, "c-ping")]//a[@rel="ugc nofollow"][@href="https://tides.example/post"]')->length)->toBe(1)
        ->and($dom->query('//article[@id="comment-123"]')->length)->toBe(1);
});

it('invites the first comment on an open story, draws nothing for a closed one with none, and "Comments are closed." under a closed one\'s', function (): void {
    $open = ($this->page)('/harbour-news/');

    expect(trim((string) $open->query('//h2[@id="comments-h"]')->item(0)?->textContent))->toBe('Comments')
        ->and(trim((string) $open->query('//*[contains(@class, "c-empty")]')->item(0)?->textContent))->toBe('No comments yet. Start the conversation.')
        ->and($open->query('//ol[@data-teme-thread][@hidden]')->length)->toBe(1)
        ->and($open->query('//section[@id="comments"]//form[@data-teme-form]')->length)->toBe(1);

    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'closed']);

    expect(($this->page)('/harbour-news/')->query('//section[@id="comments"]')->length)->toBe(0);

    cmComment($this->item);
    $closed = ($this->page)('/harbour-news/');

    expect(trim((string) $closed->query('//*[contains(@class, "c-closed")]')->item(0)?->textContent))->toBe('Comments are closed.')
        ->and($closed->query('//form[@data-teme-form]')->length)->toBe(0);
});

it('links the pages of comments older and newer, and names a later page for its story (D2)', function (): void {
    cmSettings(['per_page' => 1]);
    cmComment($this->item);
    cmComment($this->item);
    $first = ($this->page)('/harbour-news/');
    $second = dtDom((string) addrGet('/harbour-news/comments/page/2/')->assertOk()->assertHeader('X-Teme-Template', 'theme::templates.comments')->getContent());

    expect($first->query('//nav[@aria-label="Pages of comments"]//a[@rel="next"][@href="/harbour-news/comments/page/2/"]')->length)->toBe(1)
        ->and(trim((string) $second->query('//h1')->item(0)?->textContent))->toBe('Comments on “Harbour news”')
        ->and($second->query('//a[@href="/harbour-news/"][normalize-space(.)="Read the story"]')->length)->toBe(1)
        ->and((string) $second->query('//main')->item(0)?->textContent)->toContain('Page 2 of 2')
        ->and($second->query('//nav[@aria-label="Pages of comments"]//a[@rel="prev"][@href="/harbour-news/#comments"]')->length)->toBe(1);
});

it('draws the answer to a posted form, and the reply page, in the theme\'s own layout, the comment answered without its Reply (I-593, I-594)', function (): void {
    $top = cmComment($this->item, attributes: ['author_name' => 'Bo Reader']);

    cmPost($this->item, ['text' => 'Well said.'])->assertOk()->assertHeader('X-Teme-Template', 'theme::templates.respond');
    $reply = dtDom((string) addrGet("/_teme/comment/{$top->id}/reply")->assertOk()->assertHeader('X-Teme-Template', 'theme::templates.respond')->getContent());

    expect($reply->query('//*[contains(@class, "comment-answer")]//*[contains(@class, "c-context")]//*[@id="c-'.$top->id.'-by"]')->length)->toBe(1)
        ->and($reply->query('//*[contains(@class, "c-context")]//a[@data-teme-reply]')->length)->toBe(0);
});

it('draws a page\'s thread once the page is opened for comments', function (): void {
    $about = pgAuthorsPage($this->author, 'About', published: true);

    expect(($this->page)('/about/')->query('//section[@id="comments"]')->length)->toBe(0);

    DB::table('contents')->where('id', $about->id)->update(['comment_status' => 'open']);

    expect(($this->page)('/about/')->query('//section[@id="comments"]//form[@data-teme-form]')->length)->toBe(1);
});
