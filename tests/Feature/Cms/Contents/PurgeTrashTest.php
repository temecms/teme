<?php

declare(strict_types=1);

use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\RevokePreviewLinkInput;
use App\Domain\Contents\Operations\RevokePreviewLink;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('keeps trash for 30 days by default', function (): void {
    expect(app(SettingsReader::class)->int('content', 'trash_days'))->toBe(30);
});

it('deletes for good only what has been in the trash longer than the retention period', function (): void {
    $old = lifecycleTrashedDaysAgo($this->author, 'Old', 31);
    $recent = lifecycleTrashedDaysAgo($this->author, 'Recent', 29);
    $live = lifecycleDraft($this->author, 'Live');

    $this->artisan('teme:trash:purge')->expectsOutputToContain('Deleted 1')->assertSuccessful();

    expect(Content::withTrashed()->find($old->id))->toBeNull()
        ->and(Content::withTrashed()->find($recent->id))->not->toBeNull()
        ->and(Content::find($live->id))->not->toBeNull()
        ->and(ContentCounts::for('post'))->toBe(lifecycleTruth('post'));
});

it('works through a large trash in chunks', function (): void {
    foreach (range(1, 5) as $n) {
        lifecycleTrashedDaysAgo($this->author, "Old {$n}", 40);
    }

    $this->artisan('teme:trash:purge', ['--chunk' => 2])->expectsOutputToContain('Deleted 5')->assertSuccessful();

    expect(Content::onlyTrashed()->count())->toBe(0);
});

it('keeps an abandoned autosave as a revision, without moving the working revision', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $working = $content->refresh()->working_revision_id;
    edSave($content->id, $this->author, docWith(docText('Words nobody saved.')), title: 'Harbour festival 2026');
    DB::table('content_autosaves')->where('content_id', $content->id)
        ->update(['saved_at' => now()->subDays(config('editor.autosave_ttl_days') + 1)]);
    $revisions = DB::table('content_revisions')->where('content_id', $content->id)->count();

    $this->artisan('teme:trash:purge')->expectsOutputToContain('Kept 1 abandoned autosave')->assertSuccessful();

    $archived = DB::table('content_revisions')->where('content_id', $content->id)->where('kind', 'checkpoint')->sole();
    $content->refresh();

    expect(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($revisions + 1)
        ->and($archived->plain_text)->toContain('Words nobody saved.')
        ->and($archived->title)->toBe('Harbour festival 2026')
        ->and((int) $archived->author_id)->toBe($this->author->id)
        ->and($archived->id)->not->toBe($working)
        ->and($content->working_revision_id)->toBe($working)
        ->and($content->title)->toBe('Harbour festival')
        ->and(DB::table('content_autosaves')->where('content_id', $content->id)->count())->toBe(0);
});

it('leaves an autosave that is still fresh untouched', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    edSave($content->id, $this->author, docWith(docText('Still typing.')));
    $savedAt = DB::table('content_autosaves')->where('content_id', $content->id)->value('saved_at');
    $revisions = DB::table('content_revisions')->where('content_id', $content->id)->count();

    $this->artisan('teme:trash:purge')->assertSuccessful();

    expect(DB::table('content_autosaves')->where('content_id', $content->id)->value('saved_at'))->toBe($savedAt)
        ->and(DB::table('content_revisions')->where('content_id', $content->id)->count())->toBe($revisions);
});

it('deletes preview links expired or revoked more than 30 days ago, and keeps recent ones', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    $this->travel(-40)->days();
    $expiredLongAgo = histPreview($content->refresh(), $this->author);
    $revokedLongAgo = histPreview($content->refresh(), $this->author);
    app(RevokePreviewLink::class)->execute(new RevokePreviewLinkInput($revokedLongAgo->id, (string) $this->author->id));
    $this->travelBack();

    $this->travel(-3)->days();
    $expiredRecently = histPreview($content->refresh(), $this->author);
    $this->travelBack();

    $live = histPreview($content->refresh(), $this->author);

    $this->artisan('teme:trash:purge')->expectsOutputToContain('Deleted 2 old preview links')->assertSuccessful();

    expect(DB::table('preview_links')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$expiredRecently->id, $live->id])->sort()->values()->all());
});
