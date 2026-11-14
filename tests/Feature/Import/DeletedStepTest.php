<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Phases\Finish\DeletedStep;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Source\CompanionClients;
use App\Domain\Support\Security\JobActor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('author');
    $this->companion = importFake();
    $this->source = importSource();
    // A finished import, so a catch-up may start.
    $this->earlier = ImportRecords::run($this->source->id);
    // What WordPress still has: nothing, unless a test says otherwise.
    $this->companion->items('users', [])->items('terms', [])->items('urls', [])->items('comments', [])->items('content', [])
        ->items('menus', ['menus' => [], 'navigation' => []]);
});

/**
 * The map row an earlier run left for WordPress's $type $id, which became $temeType $temeId.
 *
 * @param  array<string, mixed>  $facts
 */
function delRow(string $type, string $id, string $temeType, string $temeId, array $facts = []): void
{
    ImportRecords::entry(test()->source->id, test()->earlier, $type, $id, 'created', [
        'teme_type' => $temeType, 'teme_id' => $temeId, 'facts' => $facts === [] ? '{}' : json_encode($facts),
    ]);
}

/**
 * A catch-up with the deleted step alone, run to its end.
 */
function delRun(): ImportRun
{
    importPhaseAlone(app(DeletedStep::class));

    return importRun(test()->source, null, ImportRunKind::CatchUp);
}

/**
 * The run's catch-up messages, in order: each one's kind and words.
 *
 * @return list<array{kind: string, words: string}>
 */
function delSaid(ImportRun $run, string $code = 'catch-up.deleted'): array
{
    return ImportMessageRecord::query()->where('run_id', $run->id)->whereNull('map_id')->where('code', $code)->orderBy('created_at')->orderBy('id')->get()
        ->map(fn (ImportMessageRecord $message): array => ['kind' => (string) ($message->context['kind'] ?? ''), 'words' => $message->message])->all();
}

/**
 * A post of WordPress's ID $id that the import made, published unless said otherwise; its Teme ID.
 *
 * @param  array<string, mixed>  $changes
 */
function delPost(int $id, string $title, array $changes = []): string
{
    $imported = ContentImports::import(ContentImports::item(test()->author, ['title' => $title, 'slug' => Str::slug($title), 'wordpressPath' => Str::slug($title), 'guid' => null, ...$changes]), test()->admin);
    delRow('post', (string) $id, 'content', $imported['contentId']);

    return $imported['contentId'];
}

it('lists each kind WordPress deleted since it was imported, and deletes nothing (I-345)', function (): void {
    $gonePost = delPost(12, 'Harbour news');
    $kept = delPost(13, 'Tides');
    $this->companion->items('urls', [['id' => 13, 'kind' => 'post', 'type' => 'post', 'url' => 'https://old.example/tides/']]);
    $media = lkImage($this->admin);
    delRow('attachment', '70', 'media', $media->id);
    $term = taxonomyTerm('category', 'Harbour');
    delRow('term', '30', 'term', $term->id);
    $person = User::factory()->create(['name' => 'Grace Hopper'])->assignRole('author');
    delRow('user', '7', 'user', (string) $person->id);
    $comment = cmComment(Content::query()->findOrFail($kept));
    delRow('comment', '501', 'comment', (string) $comment->id);
    $menu = navCreate($this->admin, 'Footer');
    delRow('menu', '5', 'navigation', (string) $menu->id);

    $said = delSaid(delRun());

    expect(array_column($said, 'kind'))->toBe(['user', 'term', 'attachment', 'post', 'comment', 'menu'])
        ->and($said[0]['words'])->toBe('Grace Hopper’s account was deleted on WordPress since it was imported. They can still sign in here: remove them in People if they shouldn’t.')
        ->and($said[1]['words'])->toBe('The category “Harbour” was deleted on WordPress since it was imported. It’s still here: delete it if it should go.')
        ->and($said[3]['words'])->toBe('The post “Harbour news” was deleted or moved to the trash on WordPress since it was imported. It’s still here: move it to the trash if it should go.')
        ->and($said[4]['words'])->toBe('A comment on “Tides” was deleted on WordPress since it was imported. It’s still here: move it to the trash in Comments if it should go.')
        ->and($said[5]['words'])->toBe('The menu “Footer” was deleted on WordPress since it was imported. It’s still in Navigation: delete it there if it should go.')
        ->and(DB::table('contents')->where('id', $gonePost)->whereNull('deleted_at')->exists())->toBeTrue()
        ->and(DB::table('users')->where('id', $person->id)->exists())->toBeTrue()
        ->and(DB::table('media')->where('id', $media->id)->whereNull('deleted_at')->exists())->toBeTrue();
});

it('lists nothing that is gone here as well', function (): void {
    DB::table('contents')->where('id', delPost(12, 'Harbour news'))->update(['deleted_at' => now()]);
    $kept = delPost(13, 'Tides');
    $this->companion->items('urls', [['id' => 13, 'kind' => 'post', 'type' => 'post', 'url' => 'https://old.example/tides/']]);
    $comment = cmComment(Content::query()->findOrFail($kept), 'trash');
    delRow('comment', '501', 'comment', (string) $comment->id);
    delRow('term', '30', 'term', strtolower((string) Str::ulid()));
    delRow('menu', '5', 'navigation', '999999');

    expect(delSaid(delRun()))->toBe([]);
});

it('confirms a post missing from the published list through the content list, in every status it reads', function (): void {
    delPost(13, 'A draft still there', ['status' => 'draft', 'publishedAt' => null, 'wordpressPath' => null]);
    delPost(14, 'A draft gone', ['status' => 'draft', 'publishedAt' => null, 'wordpressPath' => null]);
    $this->companion->items('content', [ContentImports::wpItem(13, ['status' => 'draft', 'slug' => 'a-draft-still-there'])]);

    $said = delSaid(delRun());
    $reads = $this->companion->requestsTo('content');

    expect(array_column($said, 'words'))->toBe(['The post “A draft gone” was deleted or moved to the trash on WordPress since it was imported. It’s still here: move it to the trash if it should go.'])
        ->and($reads)->toHaveCount(1)
        ->and($reads[0]['query']['after_id'])->toBe('12')
        ->and($reads[0]['query']['statuses'])->toBe('publish,future,draft,pending,private');
});

it('counts a deleted navigation post as a menu, never as a post (I-2670)', function (): void {
    $menu = navCreate($this->admin, 'Main');
    delRow('menu', 'navigation:40', 'navigation', (string) $menu->id);
    $this->companion->items('menus', ['menus' => [], 'navigation' => [['id' => 41, 'title' => 'Other']]]);

    expect(array_column(delSaid(delRun()), 'kind'))->toBe(['menu']);
});

it('never lists a person matched by email, whose account predates the import (I-1706)', function (): void {
    delRow('user', '8', 'user', (string) $this->admin->id, ['matched' => true]);

    expect(delSaid(delRun()))->toBe([]);
});

it('lists 500 of a kind, then says how many more', function (): void {
    foreach (range(1, 503) as $n) {
        delRow('term', (string) (1000 + $n), 'term', taxonomyTerm('post_tag', "Tag {$n}")->id);
    }

    $run = delRun();

    expect(delSaid($run))->toHaveCount(500)
        ->and(array_column(delSaid($run, 'catch-up.more'), 'words'))->toBe(['3 more categories and tags deleted on WordPress since they were imported are still here, not listed.']);
});

it('keeps only the items of the kind it asked for', function (): void {
    $media = lkImage($this->admin);
    delRow('attachment', '70', 'media', $media->id);
    // A post with the same number is no answer for the attachment.
    $this->companion->items('urls', [['id' => 70, 'kind' => 'post', 'type' => 'post', 'url' => 'https://old.example/seventy/']]);

    expect(array_column(delSaid(delRun()), 'kind'))->toBe(['attachment']);
});

it('runs only in a catch-up, registered at 1250', function (): void {
    $step = app(ImportPhases::class)->find('deleted');

    expect($step)->toBeInstanceOf(DeletedStep::class)
        ->and($step?->order())->toBe(1250)
        ->and($step?->runsIn(ImportRunKind::CatchUp))->toBeTrue()
        ->and($step?->runsIn(ImportRunKind::Full))->toBeFalse();

    delPost(12, 'Harbour news');
    importPhaseAlone(app(DeletedStep::class));
    $full = importRun($this->source);

    expect(delSaid($full))->toBe([]);
});

it('lists nothing when WordPress still has everything, and confirms nothing it needn’t', function (): void {
    delPost(12, 'Harbour news');
    $this->companion->items('urls', [['id' => 12, 'kind' => 'post', 'type' => 'post', 'url' => 'https://old.example/harbour-news/']]);
    delRow('user', '7', 'user', (string) $this->author->id);
    $this->companion->items('users', [['id' => 7, 'login' => 'ada']]);

    expect(delSaid(delRun()))->toBe([])
        ->and($this->companion->requestsTo('content'))->toBe([]);
});

it('carries on from its cursor, reading only the kinds after it', function (): void {
    delRow('user', '7', 'user', (string) User::factory()->create(['name' => 'Grace Hopper'])->id);
    $kept = delPost(13, 'Tides');
    $comment = cmComment(Content::query()->findOrFail($kept));
    delRow('comment', '501', 'comment', (string) $comment->id);
    $context = new ImportContext(strtolower((string) Str::ulid()), $this->source->id, ImportRunKind::CatchUp, JobActor::person($this->admin->id), null, 0, []);

    $result = app(DeletedStep::class)->run($context, app(CompanionClients::class)->for($this->source), 'comment:0:0');

    expect($result->cursor)->toBeNull()
        ->and(array_map(fn ($message): string => (string) $message->context['kind'], $result->messages))->toBe(['comment'])
        ->and($this->companion->requestsTo('users'))->toBe([]);
});

it('says in one line, as an error, how many earlier failures the catch-up didn’t try again, and how to retry them', function (): void {
    ImportRecords::entry($this->source->id, $this->earlier, 'attachment', '12', 'failed');
    ImportRecords::entry($this->source->id, $this->earlier, 'attachment', '13', 'failed');
    ImportRecords::entry($this->source->id, $this->earlier, 'post', '40', 'failed');

    $run = delRun();
    $line = ImportMessageRecord::query()->where('run_id', $run->id)->where('code', 'catch-up.not-retried')->sole();

    expect($line->message)->toBe('3 items that failed before weren’t tried again, since a catch-up only fetches what changed on WordPress. Run a full import to retry them.')
        ->and($line->level->value)->toBe('error')
        ->and($line->map_id)->toBeNull()
        ->and($line->context)->toBe(['count' => 3]);
});

it('says one earlier failure in the singular, and never counts what was skipped for good', function (): void {
    ImportRecords::entry($this->source->id, $this->earlier, 'attachment', '12', 'failed');
    ImportRecords::entry($this->source->id, $this->earlier, 'attachment', '13', 'skipped');
    ImportRecords::entry($this->source->id, $this->earlier, 'attachment', '14', 'skipped');

    expect(array_column(delSaid(delRun(), 'catch-up.not-retried'), 'words'))->toBe([
        '1 item that failed before wasn’t tried again, since a catch-up only fetches what changed on WordPress. Run a full import to retry it.',
    ]);
});

it('counts thousands of earlier failures with a thousands comma', function (): void {
    DB::statement(
        "INSERT INTO import_map (id, source_id, object_type, object_id, status, outcome, run_id, imported_at) SELECT lpad(g::text, 26, '0'), ?, 'attachment', g::text, 'failed', 'failed', ?, now() FROM generate_series(1, 1200) g",
        [$this->source->id, $this->earlier],
    );

    expect(array_column(delSaid(delRun(), 'catch-up.not-retried'), 'words'))->toBe([
        '1,200 items that failed before weren’t tried again, since a catch-up only fetches what changed on WordPress. Run a full import to retry them.',
    ]);
});

it('adds no such line when nothing failed before', function (): void {
    ImportRecords::entry($this->source->id, $this->earlier, 'attachment', '13', 'skipped');

    expect(delSaid(delRun(), 'catch-up.not-retried'))->toBe([]);
});
