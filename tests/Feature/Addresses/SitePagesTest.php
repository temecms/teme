<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\UpdateContentDetailsInput;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\Operations\UpdateContentDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

// The page shown as the home page and the one listing the posts can't be
// taken off the site while they are, or their address answers 404 (#36).

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->home = addrPublished($this->admin, 'Welcome', 'page');
    $this->posts = addrPublished($this->admin, 'News', 'page');
    addrChange($this->admin, ['frontShows' => 'page', 'frontPageId' => $this->home->id, 'postsPageId' => $this->posts->id]);

    // Everything a refused change could have written for the page.
    $this->written = fn (Content $page): array => [
        'content' => (array) DB::table('contents')->where('id', $page->id)->first(),
        'revisions' => DB::table('content_revisions')->where('content_id', $page->id)->count(),
        'paths' => DB::table('paths')->where('target_type', 'content')->where('target_id', $page->id)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'terms' => DB::table('content_terms')->where('content_id', $page->id)->count(),
        'counts' => DB::table('content_counts')->orderBy('type')->orderBy('bucket')->orderBy('author_id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
});

dataset('site pages', [
    'the home page' => ['home', 'This is your home page. Choose another in Settings › Reading first.'],
    'the posts page' => ['posts', 'This is your posts page. Choose another in Settings › Reading first.'],
]);

it('refuses to trash the page, in plain words, and writes nothing', function (string $which, string $message): void {
    $page = $this->{$which};
    $before = ($this->written)($page);

    expect(fn () => pageTrash($page, $this->admin))->toThrow(ValidationException::class, $message)
        ->and(($this->written)($page))->toBe($before);

    addrGet('/')->assertOk();
})->with('site pages');

it('refuses to unpublish the page, and writes nothing', function (string $which, string $message): void {
    $page = $this->{$which};
    $before = ($this->written)($page);

    expect(fn () => app(UnpublishContent::class)->execute(new ContentActionInput($page->id, (string) $this->admin->id)))->toThrow(ValidationException::class, $message)
        ->and(($this->written)($page))->toBe($before);
})->with('site pages');

it('refuses to date the page ahead, which would hide it until then, and writes nothing', function (string $which, string $message): void {
    $page = $this->{$which};
    $before = ($this->written)($page);

    expect(fn () => app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        $page->id, (string) $page->working_revision_id, (string) $this->admin->id, publishedAt: now()->addWeek()->toIso8601String(),
    )))->toThrow(ValidationException::class, $message)
        ->and(($this->written)($page))->toBe($before);
})->with('site pages');

it('can still move the page\'s date back, which keeps it on the site', function (): void {
    app(UpdateContentDetails::class)->execute(new UpdateContentDetailsInput(
        $this->home->id, (string) $this->home->working_revision_id, (string) $this->admin->id, publishedAt: now()->subYear()->toIso8601String(),
    ));

    expect($this->home->refresh()->published_at->isPast())->toBeTrue();
    addrGet('/')->assertOk();
});

it('can\'t delete the page for good either, since only trashed items can be', function (): void {
    expect(fn () => app(DeleteContentPermanently::class)->execute(new ContentActionInput($this->home->id, (string) $this->admin->id)))
        ->toThrow(ValidationException::class, 'Move it to the trash first.');
});

it('skips the page in a bulk trash or unpublish, with the reason, and does the rest', function (string $action): void {
    $other = addrPublished($this->admin, 'About', 'page');
    $before = ($this->written)($this->home);

    $this->actingAs($this->admin)
        ->post('/admin/pages/bulk', ['action' => $action, 'ids' => [$this->home->id, $other->id], 'titles' => [$this->home->id => 'Welcome']])
        ->assertSessionHasNoErrors();

    expect(session('bulk'))->toBe([
        'done' => 1,
        'skipped' => [['id' => $this->home->id, 'title' => 'Welcome', 'reason' => 'This is your home page. Choose another in Settings › Reading first.']],
    ])
        ->and(($this->written)($this->home)['content'])->toBe($before['content'])
        ->and(($this->written)($this->home)['paths'])->toBe($before['paths']);
})->with(['trash', 'unpublish']);

it('skips the page in a Bulk Edit that moves it to drafts, with the reason', function (): void {
    $before = ($this->written)($this->home);

    $this->actingAs($this->admin)->postJson('/admin/pages/bulk', [
        'action' => 'edit', 'ids' => [$this->home->id], 'titles' => [$this->home->id => 'Welcome'], 'status' => 'draft',
    ])->assertOk()
        ->assertJsonPath('done', 0)
        ->assertJsonPath('skipped.0.reason', 'This is your home page. Choose another in Settings › Reading first.');

    expect(($this->written)($this->home))->toBe($before);
});

it('shows the reason on the page\'s own Trash action', function (): void {
    $this->actingAs($this->admin)
        ->from('/admin/pages')
        ->post(route('content.action', ['type' => 'pages', 'content' => $this->home->id, 'action' => 'trash']))
        ->assertSessionHasErrors(['content' => 'This is your home page. Choose another in Settings › Reading first.']);

    expect(Content::withTrashed()->find($this->home->id)->trashed())->toBeFalse();
});

it('trashes the page once another is chosen, or the latest posts are the home page again', function (): void {
    addrChange($this->admin);

    pageTrash($this->home, $this->admin);
    pageTrash($this->posts, $this->admin);

    expect(Content::onlyTrashed()->count())->toBe(2);
    addrGet('/')->assertOk();
});
