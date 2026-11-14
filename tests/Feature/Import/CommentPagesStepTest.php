<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Redirects\Models\Redirect;
use App\Domain\Redirects\Operations\CreateRedirect;
use App\Domain\Redirects\Operations\Input\CreateRedirectInput;
use App\Domain\Redirects\Queries\SiteAnswers;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Import\CommentImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->companion = importFake();
    $this->source = importSource();
    $this->item = cmItem($this->admin);
    CommentImports::post($this->source, 20, $this->item, '/harbour-news/');
    // WordPress broke its threaded comments into pages of two.
    $this->paging = ['page_comments' => '1', 'comments_per_page' => '2', 'thread_comments' => '1', 'thread_comments_depth' => '5'];
});

/**
 * Comments 1 to $count on post 20, each starting a conversation, but for $fields.
 *
 * @param  array<string, mixed>  $fields
 *
 * @return list<array<string, mixed>>
 */
function cpsComments(int $count, array $fields = []): array
{
    return array_map(fn (int $id): array => CommentImports::item($id, $fields), range(1, $count));
}

/**
 * Every redirect's source, target kind and target, by source.
 *
 * @return list<array<string, mixed>>
 */
function cpsRedirects(): array
{
    return Redirect::query()->orderBy('source')->get()->map(fn (Redirect $redirect): array => $redirect->only(['source', 'target_kind', 'target']))->all();
}

it('sends WordPress’s comment pages to Teme’s when the paging came across', function (): void {
    $run = CommentImports::run($this->companion, $this->source, cpsComments(5), $this->paging);
    $answer = app(SiteAnswers::class)->at('/harbour-news/comment-page-2/');

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(cpsRedirects())->toBe([
            ['source' => 'harbour-news/comment-page-1', 'target_kind' => 'content', 'target' => $this->item->id],
            ['source' => 'harbour-news/comment-page-2', 'target_kind' => 'address', 'target' => '/harbour-news/comments/page/2/'],
            ['source' => 'harbour-news/comment-page-3', 'target_kind' => 'address', 'target' => '/harbour-news/comments/page/3/'],
        ])
        ->and(Redirect::query()->pluck('origin')->unique()->values()->all())->toBe(['import'])
        ->and($answer->status)->toBe(301)
        ->and((string) $answer->location)->toEndWith('/harbour-news/comments/page/2/');
});

it('sends every comment page to the item when the paging didn’t come across', function (array $options, int $pages): void {
    $comments = [...cpsComments(4), CommentImports::item(5, ['parent' => 1])];

    CommentImports::run($this->companion, $this->source, $comments, [...$this->paging, ...$options]);

    expect(Redirect::query()->count())->toBe($pages)
        ->and(Redirect::query()->where('target_kind', '<>', 'content')->count())->toBe(0);
})->with([
    'threading off: every approved comment counts' => [['thread_comments' => '0'], 3],
    'one level only: every approved comment counts' => [['thread_comments_depth' => '1'], 3],
    'more to a page than Teme shows' => [['comments_per_page' => '300'], 1],
]);

it('redirects nothing when WordPress didn’t break comments into pages', function (): void {
    CommentImports::run($this->companion, $this->source, cpsComments(5), [...$this->paging, 'page_comments' => '0']);

    expect(Redirect::query()->count())->toBe(0);
});

it('starts from the item’s WordPress address, or its Teme address without one', function (): void {
    CommentImports::post($this->source, 21, cmItem($this->admin, 'Ferry times'));
    DB::table('import_map')->where('object_type', 'post')->where('object_id', '20')->update(['facts' => json_encode(['path' => '/2019/05/harbour-news/'])]);

    CommentImports::run($this->companion, $this->source, [CommentImports::item(1), CommentImports::item(2, ['post_id' => 21])], $this->paging);

    expect(Redirect::query()->orderBy('source')->pluck('source')->all())->toBe(['2019/05/harbour-news/comment-page-1', 'ferry-times/comment-page-1']);
});

it('makes no pages of comments that wait, are spam or are in the trash', function (): void {
    CommentImports::run($this->companion, $this->source, [
        CommentImports::item(1, ['status' => '0']), CommentImports::item(2, ['status' => 'spam']), CommentImports::item(3, ['status' => 'trash']),
    ], $this->paging);

    expect(Redirect::query()->count())->toBe(0);
});

it('never makes a redirect twice, and adds a page that’s new since', function (): void {
    CommentImports::run($this->companion, $this->source, cpsComments(2), $this->paging);
    CommentImports::run($this->companion, $this->source, cpsComments(3), $this->paging);

    expect(Redirect::query()->orderBy('source')->pluck('source')->all())->toBe(['harbour-news/comment-page-1', 'harbour-news/comment-page-2']);
});

it('leaves a redirect someone made as it is, and reports one that would clash', function (): void {
    app(CreateRedirect::class)->execute(new CreateRedirectInput('/harbour-news/comment-page-1/', 301, '/elsewhere/', actorId: (string) $this->admin->id));
    app(CreateRedirect::class)->execute(new CreateRedirectInput('/old-thread/', 301, '/harbour-news/comment-page-2/', actorId: (string) $this->admin->id));

    $run = CommentImports::run($this->companion, $this->source, cpsComments(5), $this->paging);

    expect(Redirect::query()->where('source', 'harbour-news/comment-page-1')->value('target'))->toBe('/elsewhere/')
        ->and(Redirect::query()->where('source', 'harbour-news/comment-page-2')->exists())->toBeFalse()
        ->and(Redirect::query()->where('source', 'harbour-news/comment-page-3')->exists())->toBeTrue()
        ->and(ImportMessageRecord::query()->where('run_id', $run->id)->whereNull('map_id')->pluck('message')->all())->toBe([
            'Teme couldn’t redirect 1 of WordPress’s comment page addresses, because a redirect there would clash with another: /harbour-news/comment-page-2/.',
        ]);
});
