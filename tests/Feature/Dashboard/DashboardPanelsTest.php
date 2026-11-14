<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Dashboard\Queries\Dashboard;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Ngozi Editor'])->assignRole('editor');
    $this->author = User::factory()->create(['name' => 'Chidi Author'])->assignRole('author');
    $this->contributor = User::factory()->create(['name' => 'Tamuno Contributor'])->assignRole('contributor');
});

it('shows an editor what waits for their review, oldest first, and nobody who cannot decide', function (): void {
    $older = lifecycleDraft($this->contributor, 'Night market');
    schedSubmit($older, $this->contributor);
    $this->travel(1)->hours();
    $newer = lifecycleDraft($this->contributor, 'Harbour festival');
    schedSubmit($newer, $this->contributor);

    $waiting = dashPanel($this->editor, 'waiting_for_my_review');

    expect(array_column($waiting['rows'], 'id'))->toBe([$older->id, $newer->id])
        ->and($waiting['rows'][0]['subtitle'])->toStartWith('Submitted by Tamuno Contributor · ')
        // The contributor lacks the type's publish ability.
        ->and(dashKeys($this->contributor))->not->toContain('waiting_for_my_review')
        // The author holds the type's publish ability but not its edit.any, and
        // deciding on someone else's item needs both (ContentPolicy).
        ->and(dashKeys($this->author))->not->toContain('waiting_for_my_review');
});

it('shows a writer what they have in review, a live article\'s update included, and what was returned to them', function (): void {
    $kept = lifecycleDraft($this->contributor, 'Night market');
    schedSubmit($kept, $this->contributor);
    $returned = lifecycleDraft($this->contributor, 'Harbour festival');
    schedReturn(schedSubmit($returned, $this->contributor), $this->editor);

    $this->travel(1)->minutes();
    $live = lifecycleDraft($this->contributor, 'Ferry fares');
    listsPublish($live, $this->editor);
    docSave($live->refresh(), $this->contributor, docWith(docText('A correction.')));
    schedSubmit($live, $this->contributor);

    expect(array_column(dashPanel($this->contributor, 'my_items_in_review')['rows'], 'id'))->toBe([$live->id, $kept->id])
        ->and(array_column(dashPanel($this->contributor, 'returned_to_me')['rows'], 'id'))->toBe([$returned->id]);
});

it('shows the reviewer\'s note, cut at 120 characters, and who returned it', function (): void {
    $note = rtrim(str_repeat('Needs a source. ', 10));
    $content = lifecycleDraft($this->contributor, 'Night market');
    schedReturn(schedSubmit($content, $this->contributor), $this->editor, $note);

    expect(dashPanel($this->contributor, 'returned_to_me')['rows'][0]['subtitle'])
        ->toBe(str_repeat('Needs a source. ', 7).'Needs a… · returned by Ngozi Editor');
});

it('drops a returned item from Returned to you once it is submitted again', function (): void {
    $content = lifecycleDraft($this->contributor, 'Night market');
    schedReturn(schedSubmit($content, $this->contributor), $this->editor);
    $this->travel(1)->minutes();
    schedSubmit($content, $this->contributor);

    expect(dashKeys($this->contributor))->not->toContain('returned_to_me')
        ->and(array_column(dashPanel($this->contributor, 'my_items_in_review')['rows'], 'id'))->toBe([$content->id]);
});

it('lists a person\'s own drafts, most recently changed first, and nobody else\'s', function (): void {
    $first = lifecycleDraft($this->author, 'Night market');
    $this->travel(1)->minutes();
    $second = lifecycleDraft($this->author, 'Harbour festival');
    lifecycleDraft($this->editor, 'Not theirs');

    $rows = dashPanel($this->author, 'my_drafts')['rows'];

    expect(array_column($rows, 'id'))->toBe([$second->id, $first->id])
        ->and($rows[0]['subtitle'])->toStartWith('Edited ')
        ->and($rows[0]['subtitle'])->toEndWith(' · Post');
});

it('lists what is scheduled, soonest first: everything for an editor, only their own for an author', function (): void {
    $other = User::factory()->create()->assignRole('author');
    $later = lifecycleDraft($this->author, 'Night market');
    schedFor($later, $this->editor, now()->addDays(2)->toIso8601String());
    $sooner = lifecycleDraft($other, 'Harbour festival');
    schedFor($sooner, $this->editor, now()->addDay()->toIso8601String());

    // A scheduled update to a live article stays published, and still counts.
    $live = lifecycleDraft($this->author, 'Ferry fares');
    listsPublish($live, $this->author);
    docSave($live->refresh(), $this->author, docWith(docText('The update, for Friday.')));
    schedFor($live, $this->author, now()->addDays(3)->toIso8601String(), 'update');

    // The split is the type's edit.any ability, which the editor holds and the
    // author does not. Never a role name.
    expect(array_column(dashPanel($this->editor, 'scheduled')['rows'], 'id'))->toBe([$sooner->id, $later->id, $live->id])
        ->and(array_column(dashPanel($this->author, 'scheduled')['rows'], 'id'))->toBe([$later->id, $live->id]);
});

it('shows a held schedule with the reason it is held', function (): void {
    $scheduler = User::factory()->create()->assignRole('editor');
    $content = lifecycleDraft($this->author, 'Night market');
    schedFor($content, $scheduler, now()->addMinute()->toIso8601String());
    $scheduler->syncRoles(['contributor']);
    $this->travel(2)->minutes();

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    $rows = dashPanel($this->editor, 'scheduled')['rows'];

    expect(array_column($rows, 'id'))->toBe([$content->id])
        ->and($rows[0]['subtitle'])->toStartWith('Held: the person who scheduled it can no longer publish');
});

it('shows a failed schedule as failed, because a failure leaves the item scheduled', function (): void {
    $content = lifecycleDraft($this->author, 'Night market');
    schedDue($content, $this->editor);
    $this->app->bind(PublishRevision::class, fn () => throw new RuntimeException('The search index is unavailable.'));

    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    $rows = dashPanel($this->editor, 'scheduled')['rows'];

    // B-167: failed_at is set, contents.scheduled_for is not cleared.
    expect(array_column($rows, 'id'))->toBe([$content->id])
        ->and($rows[0]['subtitle'])->toStartWith('Failed · was due ');
});

it('lists the articles a person edited last, each once, newest first', function (): void {
    $first = lifecycleDraft($this->author, 'Night market');
    $second = lifecycleDraft($this->author, 'Harbour festival');

    foreach (['One.', 'Two.', 'Three.'] as $text) {
        $this->travel(1)->minutes();
        docSave($first, $this->author, docWith(docText($text)));
    }

    $this->travel(1)->minutes();
    docSave($second, $this->author, docWith(docText('Once.')));

    expect(array_column(dashPanel($this->author, 'my_recent_edits')['rows'], 'id'))->toBe([$second->id, $first->id]);
});

it('leaves out a panel with nothing in it', function (): void {
    expect(dashKeys($this->author))->toBe([]);
});

it('never asks for more than five rows per panel', function (): void {
    foreach (range(1, 7) as $n) {
        $this->travel(1)->minutes();
        lifecycleDraft($this->author, "Draft {$n}");
    }

    $drafts = dashPanel($this->author, 'my_drafts');

    expect($drafts['rows'])->toHaveCount(5)
        ->and(array_column($drafts['rows'], 'title'))->toBe(['Draft 7', 'Draft 6', 'Draft 5', 'Draft 4', 'Draft 3'])
        ->and($drafts['seeAll'])->toBe(route('content.index', ['type' => 'posts', 'tab' => 'drafts', 'author' => $this->author->id]));
});

it('makes the same number of queries whatever the site holds', function (): void {
    $dashboard = fn (): array => app(Dashboard::class)->forUser($this->editor);
    $dashboard(); // warms the permission cache

    $empty = count(dashQueries($dashboard));

    foreach (range(1, 7) as $n) {
        lifecycleDraft($this->editor, "Draft {$n}");
        schedSubmit(lifecycleDraft($this->contributor, "Submitted {$n}"), $this->contributor);
        schedFor(lifecycleDraft($this->author, "Scheduled {$n}"), $this->editor, now()->addDays($n)->toIso8601String());
    }

    expect(dashKeys($this->editor))->toContain('waiting_for_my_review', 'my_drafts', 'scheduled', 'my_recent_edits')
        ->and(count(dashQueries($dashboard)))->toBe($empty);
});

it('reads the site figures from the maintained counts, not by counting rows', function (): void {
    listsPublish(lifecycleDraft($this->author, 'Night market'), $this->author);
    lifecycleDraft($this->author, 'Harbour festival');
    lifecycleDraft($this->editor, 'About us', 'page');

    $sql = dashQueries(fn (): array => app(Dashboard::class)->forUser($this->editor));
    $figures = collect(app(Dashboard::class)->forUser($this->editor)['figures'])->pluck('value', 'key');

    // toContain, not an exact list: 1.8 adds Comments to this row.
    expect($figures->keys()->all())->toContain('post.published', 'post.drafts', 'post.scheduled', 'page.published', 'page.drafts', 'page.scheduled', 'media')
        ->and($figures['post.published'])->toBe(lifecycleTruth('post')['published'])
        ->and($figures['post.drafts'])->toBe(lifecycleTruth('post')['draft'])
        ->and($figures['page.drafts'])->toBe(lifecycleTruth('page')['draft'])
        ->and($figures['post.scheduled'])->toBe(0)
        ->and($figures['media'])->toBe(0)
        ->and(collect($sql)->filter(fn (string $statement): bool => (bool) preg_match('/count\s*\(/i', $statement) && str_contains($statement, '"contents"')))->toBeEmpty();
});

it('reads the site figures from the status rows only, never the month rows (I-3131)', function (): void {
    listsPublish(lifecycleDraft($this->editor, 'Harbour news'), $this->editor);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $figures = collect(app(Dashboard::class)->forUser($this->editor)['figures'])->pluck('value', 'key');
    // SiteFigures' read, the one that asks for several types at once (GettingStarted asks for one).
    $read = collect(DB::getQueryLog())->first(fn (array $query): bool => str_contains($query['query'], 'from "content_counts"') && str_contains($query['query'], '"type" in ('));

    expect($figures['post.published'])->toBe(1)
        ->and($read['query'] ?? '')->toContain('"bucket" in (?, ?, ?)')
        ->and($read['bindings'] ?? [])->toContain('published', 'draft', 'scheduled');
});
