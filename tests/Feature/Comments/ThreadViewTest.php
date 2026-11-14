<?php

declare(strict_types=1);

use App\Domain\Comments\CommentPicture;
use App\Domain\Comments\Operations\ModerateComment;
use App\Domain\Contents\Models\Content;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\CommentViews;
use App\Domain\Site\RespondViews;
use App\Domain\Site\SiteDates;
use App\Domain\Site\ViewModels\CommentView;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->item = cmItem($this->author);
    $this->views = app(CommentViews::class);
    $this->ids = fn (?array $views): array => array_map(fn (CommentView $view): string => $view->id, $views ?? []);
});

it('gives a theme no comment data while comments are switched off, the item is Hidden, or its type takes none (I-484, I-274)', function (): void {
    cmComment($this->item);

    expect($this->views->forItem($this->item))->not->toBeNull();

    cmSettings(['comments' => 'off'], 'modules');

    expect($this->views->forItem($this->item))->toBeNull();

    cmSettings(['comments' => 'on'], 'modules');
    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'hidden']);

    expect($this->views->forItem($this->item->refresh()))->toBeNull();

    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'open', 'type' => 'region']);

    expect($this->views->forItem($this->item->refresh()))->toBeNull();
});

it('draws the form only while the item is Open and within the days the settings allow, and its comments either way (I-274)', function (): void {
    cmComment($this->item);
    $open = $this->views->forItem($this->item);

    expect($open?->open)->toBeTrue()->and($open?->form)->not->toBeNull()->and($open?->count)->toBe(1);

    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'closed']);
    $closed = $this->views->forItem($this->item->refresh());

    expect($closed?->open)->toBeFalse()->and($closed?->form)->toBeNull()->and($closed?->count)->toBe(1);

    DB::table('contents')->where('id', $this->item->id)->update(['comment_status' => 'open']);
    cmSettings(['close_after_days' => 30]);
    $this->travel(31)->days();

    expect($this->views->forItem($this->item->refresh())?->open)->toBeFalse();
});

it('tells readers for how many days their address is kept, as Settings › Discussion says (I-270)', function (): void {
    $comment = cmComment($this->item);

    expect($this->views->forItem($this->item)?->form?->keepIpDays)->toBe(60);

    cmSettings(['keep_ip_days' => 30]);

    expect($this->views->forItem($this->item)?->form?->keepIpDays)->toBe(30)
        ->and(app(RespondViews::class)->reply($comment->id)?->form?->keepIpDays)->toBe(30);
});

it('counts every approved comment, pings too, and pages the conversations oldest first, each whole (D2, D4)', function (): void {
    cmSettings(['per_page' => 2]);
    $first = cmComment($this->item);
    $reply = cmComment($this->item, parent: $first);
    $ping = cmComment($this->item, attributes: ['kind' => 'pingback', 'author_name' => 'Tide Watch', 'author_url' => 'https://tides.example/post', 'author_email' => null]);
    $third = cmComment($this->item);
    cmComment($this->item, 'pending');

    $one = $this->views->forItem($this->item);
    $two = $this->views->forItem($this->item, 2);

    expect($one?->count)->toBe(4)
        ->and($one?->total)->toBe(2)
        ->and(($this->ids)($one?->items))->toBe([$first->id, $ping->id])
        ->and(($this->ids)($one?->items[0]->replies))->toBe([$reply->id])
        ->and($one?->items[1]->bodyHtml)->toBe('')
        ->and($one?->pagination->next)->toBe('/harbour-news/comments/page/2/')
        ->and(($this->ids)($two?->items))->toBe([$third->id])
        ->and($two?->pagination->previous)->toBe('/harbour-news/#comments')
        ->and($two?->pagination->next)->toBeNull()
        ->and($this->views->forItem($this->item, 3))->toBeNull();
});

it('keeps a tombstone\'s place with nothing personal in it, and says whom each reply answers (I-278, I-595)', function (): void {
    $top = cmComment($this->item);
    $reply = cmComment($this->item, parent: $top, attributes: ['author_name' => 'Bo Reader']);
    cmComment($this->item, parent: $reply);
    cmModerate($top, 'trash', $this->author);
    cmDelete($top->refresh(), $this->author);

    $tombstone = $this->views->forItem($this->item)?->items[0];

    expect($tombstone?->removed)->toBeTrue()
        ->and($tombstone?->name)->toBeNull()
        ->and($tombstone?->initials)->toBe('')
        ->and($tombstone?->bodyHtml)->toBe('')
        ->and($tombstone?->replyUrl)->toBeNull()
        ->and($tombstone?->replies[0]->isReply)->toBeTrue()
        ->and($tombstone?->replies[0]->parentName)->toBeNull()
        ->and($tombstone?->replies[0]->replies[0]->parentName)->toBe('Bo Reader');
});

it('hides a reply while the comment above it waits, is spam or is in the trash, and shows it again when that comes back (D3)', function (): void {
    $top = cmComment($this->item);
    $reply = cmComment($this->item, parent: $top);
    $later = cmComment($this->item);

    cmModerate($top, 'trash', $this->author);

    expect(($this->ids)($this->views->forItem($this->item)?->items))->toBe([$later->id])
        ->and($this->views->forItem($this->item)?->count)->toBe(2);

    cmModerate($top, ModerateComment::RESTORE, $this->author);
    $view = $this->views->forItem($this->item);

    expect(($this->ids)($view?->items))->toBe([$top->id, $later->id])
        ->and(($this->ids)($view?->items[0]->replies))->toBe([$reply->id]);
});

it('marks the story\'s own author by their account, never by a name a guest can type (I-597)', function (): void {
    cmComment($this->item, attributes: ['author_name' => 'Jane Doe', 'author_user_id' => $this->author->id]);
    cmComment($this->item, attributes: ['author_name' => 'Jane Doe']);
    $items = $this->views->forItem($this->item)?->items ?? [];

    expect($items[0]->byAuthor)->toBeTrue()
        ->and($items[1]->byAuthor)->toBeFalse();
});

it('gives a theme a name, the website its writer chose, a picture and a time, and never an email address, an IP address, a browser or a moderation record (D19)', function (): void {
    cmComment($this->item, attributes: [
        'author_name' => 'Ada Guest',
        'author_url' => 'https://ada.example',
        'legacy_id' => 123,
        'ip' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 (Test)',
        'commenter_token_hash' => hash('sha256', 'browser-one-0123456789abcdef'),
        'moderation' => ['by' => 'Teme', 'status' => 'approved', 'reason' => 'Approved: a returning commenter.', 'signals' => ['rule' => 'returning']],
    ]);
    $view = $this->views->forItem($this->item);
    $comment = $view?->items[0];

    expect($comment?->name)->toBe('Ada Guest')
        ->and($comment?->url)->toBe('https://ada.example')
        ->and($comment?->initials)->toBe('AG')
        ->and($comment?->hue)->toBe(CommentPicture::hue('Ada Guest'))
        ->and($comment?->iso)->toBe(SiteDates::inSite(now())->toIso8601String())
        ->and($comment?->legacyId)->toBe(123)
        ->and($comment?->permalink)->toBe("/_teme/comment/{$comment?->id}")
        ->and($comment?->replyUrl)->toBe("/_teme/comment/{$comment?->id}/reply")
        ->and((string) json_encode($view))->not->toContain('ada@example.com')->not->toContain('203.0.113.7')->not->toContain('Mozilla')->not->toContain('returning');
});

it('records the item\'s thread, each comment it draws and the Discussion settings on the page, and nothing it hides (D5, D6)', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $folder = cacheOn();
    $top = cmComment($this->item);
    $reply = cmComment($this->item, parent: $top);
    $waiting = cmComment($this->item, 'pending');

    addrGet('/harbour-news/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheEntry('/harbour-news/')?->tags)
        ->toContain('comments:'.$this->item->id, 'comment:'.$top->id, 'comment:'.$reply->id, 'discussion')
        ->not->toContain('comment:'.$waiting->id);

    cacheOff($folder);
});

it('reads the same four statements for a thread of one comment as for one of thirty, and none for an item that shows none', function (): void {
    $small = cmItem($this->author, 'Small news');
    cmComment($small);

    foreach (range(1, 10) as $n) {
        cmComment($this->item, parent: cmComment($this->item, parent: cmComment($this->item)));
    }

    // The settings are read once a request, and kept (Cache::memo()).
    $this->views->forItem($small);
    $statements = fn (Content $item): int => count(planStatements(fn () => $this->views->forItem($item)));

    expect($statements($small))->toBe(4)
        ->and($statements($this->item))->toBe(4);

    DB::table('contents')->where('id', $small->id)->update(['comment_status' => 'hidden']);

    expect($statements($small->refresh()))->toBe(0);
});

it('gives an article its thread, and a preview none (D20)', function (): void {
    cmComment($this->item);

    expect(app(ArticleViews::class)->published($this->item)->comments?->count)->toBe(1)
        ->and(app(ArticleViews::class)->ofRevision($this->item->id, (string) $this->item->published_revision_id)->comments)->toBeNull();
});
