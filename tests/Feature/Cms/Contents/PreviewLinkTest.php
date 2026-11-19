<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\RevokePreviewLinkInput;
use App\Domain\Contents\Operations\RevokePreviewLink;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Queries\PreviewLinkList;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('shows the revision the link was made for', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Three days of music by the quay.')));
    $link = histPreview($content->refresh(), $this->author);

    $this->get("/preview/{$link->token}")->assertOk()->assertSee('Three days of music');
});

it('shows the revision it was made for, not whatever the article says now', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('What I shared.')));
    $link = histPreview($content->refresh(), $this->author);
    docSave($content->refresh(), $this->author, docWith(docText('What I wrote afterwards.')));

    $this->get("/preview/{$link->token}")->assertSee('What I shared.')->assertDontSee('What I wrote afterwards.');
});

it('refuses a token that is wrong, expired or revoked', function (string $case): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $link = histPreview($content->refresh(), $this->author);
    $linkId = DB::table('preview_links')->where('content_id', $content->id)->value('id');

    $token = match ($case) {
        'wrong' => substr($link->token, 0, -1).(str_ends_with($link->token, 'a') ? 'b' : 'a'),
        'expired' => $link->token,
        'revoked' => $link->token,
    };

    if ($case === 'expired') {
        $this->travel(config('editor.preview_hours') + 1)->hours();
    }

    if ($case === 'revoked') {
        app(RevokePreviewLink::class)->execute(new RevokePreviewLinkInput($linkId, (string) $this->author->id));
    }

    $this->get("/preview/{$token}")
        ->assertNotFound()
        ->assertSee('Link expired')
        ->assertSee('This preview link has expired')
        ->assertSee('Preview links stop working after a while, so an unfinished article doesn’t stay open to anyone with the address. Ask whoever sent it to you for a new one.', false)
        ->assertSee('Go to the site')
        ->assertSee('href="'.url('/').'"', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private');
})->with(['wrong', 'expired', 'revoked']);

it('answers a wrong, an expired and a revoked link with the very same page', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $link = histPreview($content->refresh(), $this->author);
    $linkId = DB::table('preview_links')->where('content_id', $content->id)->value('id');

    $wrong = $this->get('/preview/'.substr($link->token, 0, -1).(str_ends_with($link->token, 'a') ? 'b' : 'a'))->getContent();

    app(RevokePreviewLink::class)->execute(new RevokePreviewLinkInput($linkId, (string) $this->author->id));
    $revoked = $this->get("/preview/{$link->token}")->getContent();

    expect($revoked)->toBe($wrong)->and($wrong)->not->toContain('Harbour festival');
    expect((int) DB::table('preview_links')->where('id', $linkId)->value('view_count'))->toBe(0);
});

it('stores only a hash of the token', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $link = histPreview($content->refresh(), $this->author);

    expect(DB::table('preview_links')->where('content_id', $content->id)->value('token_hash'))->not->toBe($link->token);
});

it('does not need an account', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $link = histPreview($content->refresh(), $this->author);

    $this->assertGuest();
    $this->get("/preview/{$link->token}")->assertOk();
});

it('grants nothing but that one revision', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $link = histPreview($content->refresh(), $this->author);

    $this->get("/preview/{$link->token}")->assertOk();

    $this->assertGuest();                                // viewing signs no one in
    $this->get('/admin')->assertRedirect();              // so the admin still asks for a sign-in
});

it('records that it was viewed, and when', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $link = histPreview($content->refresh(), $this->author);
    $this->freezeTime();

    $this->get("/preview/{$link->token}")->assertOk();
    $this->get("/preview/{$link->token}")->assertOk();

    $row = DB::table('preview_links')->where('content_id', $content->id)->first();
    expect($row->view_count)->toBe(2)
        ->and(CarbonImmutable::parse($row->last_viewed_at)->equalTo(now()))->toBeTrue();
});

it('expires after the configured window', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $link = histPreview($content->refresh(), $this->author);

    $this->travel(config('editor.preview_hours') - 1)->hours();
    $this->get("/preview/{$link->token}")->assertOk();

    $this->travel(2)->hours();
    $this->get("/preview/{$link->token}")->assertNotFound();
});

it('lets the writer see and revoke what they have shared', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $link = histPreview($content->refresh(), $this->author);

    $links = app(PreviewLinkList::class)->forContent($content->id, $this->author);
    expect($links)->toHaveCount(1)
        ->and($links[0]['live'])->toBeTrue()
        ->and($links[0]['createdBy'])->toBe((string) $this->author->id);

    app(RevokePreviewLink::class)->execute(new RevokePreviewLinkInput($links[0]['id'], (string) $this->author->id));

    expect(app(PreviewLinkList::class)->forContent($content->id, $this->author)[0]['live'])->toBeFalse();
    $this->get("/preview/{$link->token}")->assertNotFound();
});

it('stops resolving while its article is in the trash', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $link = histPreview($content->refresh(), $this->author);

    app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $this->author->id));

    $this->get("/preview/{$link->token}")->assertNotFound();
});

it('writes nothing when someone may not revoke the link', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    histPreview($content->refresh(), $this->author);
    $linkId = DB::table('preview_links')->where('content_id', $content->id)->value('id');
    $stranger = User::factory()->create()->assignRole('author');

    expect(fn () => app(RevokePreviewLink::class)->execute(new RevokePreviewLinkInput($linkId, (string) $stranger->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('preview_links')->where('id', $linkId)->value('revoked_at'))->toBeNull();
});

it('answers 404 when the link in the URL belongs to another article', function (): void {
    $mine = lifecycleDraft($this->author, 'Harbour festival');
    $other = lifecycleDraft($this->author, 'Lighthouse talk');
    $link = histPreview($other->refresh(), $this->author);

    $this->actingAs($this->author)
        ->delete(route('content.preview-links.destroy', ['content' => $mine->id, 'link' => $link->id]))
        ->assertNotFound();

    expect(DB::table('preview_links')->where('id', $link->id)->value('revoked_at'))->toBeNull();
});

it('is refused to someone who may not edit the article, with a 403 on making and on listing', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    histPreview($content->refresh(), $this->author);
    $stranger = User::factory()->create()->assignRole('author');

    // Making one, not viewing one.
    $this->actingAs($stranger)
        ->postJson(route('content.preview-links.store', $content->id))
        ->assertForbidden();

    $this->actingAs($stranger)
        ->getJson(route('content.preview-links.index', $content->id))
        ->assertForbidden();

    expect(DB::table('preview_links')->where('content_id', $content->id)->count())->toBe(1)
        ->and(app(PreviewLinkList::class)->forContent($content->id, $stranger))->toBeNull();
});

it('makes and lists links for someone who may edit the article', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    $url = $this->actingAs($this->author)
        ->postJson(route('content.preview-links.store', $content->id))
        ->assertCreated()
        ->json('url');

    $this->actingAs($this->author)
        ->getJson(route('content.preview-links.index', $content->id))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.live', true);

    auth()->logout();
    $this->get(parse_url($url, PHP_URL_PATH))->assertOk();
});

it('draws Share only for someone who may edit, and promises nothing beside Save', function (): void {
    expect(uiComponent('components/editor/editor-header.tsx'))->toContain('canShare')
        // 1.6e: never for a theme region, which has no page to preview.
        ->and(uiComponent('pages/content/edit.tsx'))->toContain('canShare={session.can.edit && themeRegion === null}')
        ->and(uiComponent('components/editor/editor-rail.tsx'))->not->toContain('Later (1.5d)');
});

it('renders a preview through the theme\'s single template, marked and not indexable', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $content = pageArticle($this->author, 'Published title');
    pageSaveDraft($content, 'Draft title');

    $link = histPreview($content->refresh(), $this->author);

    $this->get("/preview/{$link->token}")
        ->assertOk()
        ->assertSee('data-template="single"', false)
        ->assertSee('Draft title')
        ->assertSee('Preview of an unpublished version')
        ->assertSee('--teme-bar-ink', false)
        ->assertSee('<svg aria-hidden="true"', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('names who made each link, and nobody for a link whose maker\'s account is gone', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $editor = User::factory()->create(['name' => 'Eve Editor'])->assignRole('editor');
    histPreview($content->refresh(), $this->author);
    $this->travel(1)->minutes();
    $theirs = histPreview($content->refresh(), $editor);
    DB::table('preview_links')->where('id', $theirs->id)->update(['created_by' => null]);

    $links = app(PreviewLinkList::class)->forContent($content->id, $this->author);

    // Newest first.
    expect(array_column($links, 'createdByName'))->toBe([null, $this->author->name]);
});
