<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\RebuildDocumentDerivationsInput;
use App\Domain\Contents\Operations\RebuildDocumentDerivations;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');

    // An item published before 1.5b: its revisions have no plain text and the
    // item has no search columns, exactly as 1.5b's migration left them.
    $this->content = lifecycleDraft($this->author, 'Untitled news');
    docSave($this->content, $this->author, docWith(docText('The lifeboat crew marked fifty years of service.')));
    listsPublish($this->content->refresh(), $this->author);
    $this->revisionId = $this->content->refresh()->published_revision_id;

    DB::table('content_revisions')->where('content_id', $this->content->id)->update(['plain_text' => null, 'word_count' => 0]);
    DB::table('contents')->where('id', $this->content->id)->update(['search_text' => null, 'search_tsv' => null]);
});

it('fills in the plain text and word count of a revision written without them', function (): void {
    $this->artisan('teme:content:rebuild-derivations')->assertSuccessful();

    $revision = DB::table('content_revisions')->where('id', $this->revisionId)->first(['plain_text', 'word_count']);

    expect($revision->plain_text)->toBe('The lifeboat crew marked fifty years of service.')
        ->and($revision->word_count)->toBe(8)
        ->and(DB::table('content_revisions')->whereNull('plain_text')->count())->toBe(0);
});

it('changes nothing on a second run', function (): void {
    $this->artisan('teme:content:rebuild-derivations')->assertSuccessful();
    $revisions = DB::table('content_revisions')->orderBy('id')->get(['id', 'plain_text', 'word_count'])->all();
    $search = DB::table('contents')->orderBy('id')->pluck('search_text', 'id')->all();

    $this->artisan('teme:content:rebuild-derivations')
        ->expectsOutputToContain('Nothing to fill in')
        ->assertSuccessful();

    expect(DB::table('content_revisions')->orderBy('id')->get(['id', 'plain_text', 'word_count'])->all())->toEqual($revisions)
        ->and(DB::table('contents')->orderBy('id')->pluck('search_text', 'id')->all())->toBe($search);
});

it('makes an older published item findable by a word in its body', function (): void {
    $found = fn () => DB::table('contents')->whereRaw("search_tsv @@ plainto_tsquery('english', ?)", ['lifeboat crew'])->pluck('id');

    expect($found())->not->toContain($this->content->id);

    $this->artisan('teme:content:rebuild-derivations')->assertSuccessful();

    expect($found())->toContain($this->content->id);
});

it('fails the check before the fill, writes nothing while checking, and passes after', function (): void {
    $this->artisan('teme:content:rebuild-derivations', ['--check' => true])->assertFailed();

    expect(DB::table('content_revisions')->where('content_id', $this->content->id)->whereNull('plain_text')->count())->toBe(2);

    $this->artisan('teme:content:rebuild-derivations')->assertSuccessful();
    $this->artisan('teme:content:rebuild-derivations', ['--check' => true])->assertSuccessful();
});

it('lets only the operator fill in derived values, and writes nothing otherwise', function (): void {
    expect(fn () => app(RebuildDocumentDerivations::class)->execute(new RebuildDocumentDerivationsInput([$this->content->id], fix: true)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('content_revisions')->where('content_id', $this->content->id)->whereNull('plain_text')->count())->toBe(2)
        ->and(DB::table('contents')->where('id', $this->content->id)->value('search_text'))->toBeNull();
});
