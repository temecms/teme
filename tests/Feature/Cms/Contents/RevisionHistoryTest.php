<?php

declare(strict_types=1);

use App\Domain\Contents\Queries\RevisionHistory;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('lists revisions newest first, with who made each one', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('One sentence here.')));
    docSave($content->refresh(), $this->author, docWith(docText('One sentence here. And a second one.')));

    $rows = RevisionHistory::for($content->id, $this->author)->rows;

    expect($rows[0]['author'])->toBe($this->author->name)
        ->and($rows[0]['createdAt'])->toBeGreaterThanOrEqual($rows[1]['createdAt']);
});

it('says how much each revision changed', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('One two three four five.')));
    docSave($content->refresh(), $this->author, docWith(docText('One two three.')));

    $rows = RevisionHistory::for($content->id, $this->author)->rows;

    expect($rows[0]['wordDelta'])->toBe(-2);
});

it('marks which revision is published and which is being worked on', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Ready.')));
    listsPublish($content->refresh(), $this->author);
    docSave($content->refresh(), $this->author, docWith(docText('Changed since.')));

    $rows = RevisionHistory::for($content->id, $this->author)->rows;
    $published = array_values(array_filter($rows, fn (array $r): bool => $r['isPublished']));
    $working = array_values(array_filter($rows, fn (array $r): bool => $r['isWorking']));

    expect($published)->toHaveCount(1)->and($working)->toHaveCount(1)
        ->and($published[0]['id'])->not->toBe($working[0]['id']);
});

it('pages by keyset without counting, and takes the same number of queries on every page', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    foreach (range(1, RevisionHistory::PAGE + 5) as $n) {
        $this->travel(1)->minutes();
        docSave($content->refresh(), $this->author, docWith(docText(str_repeat('word ', $n))));
    }

    $count = function (?string $cursor) use ($content): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = RevisionHistory::for($content->id, $this->author, $cursor);
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));

        return [$page, count(DB::getQueryLog()), $sql];
    };

    // Warm the permission cache first, so both pages measure the same work.
    RevisionHistory::for($content->id, $this->author);

    [$first, $firstQueries, $firstSql] = $count(null);
    [$second, $secondQueries, $secondSql] = $count($first->older);

    // lifecycleDraft's revision plus PAGE + 5 saves: a full page, then the rest.
    expect($first->rows)->toHaveCount(RevisionHistory::PAGE)
        ->and($first->older)->not->toBeNull()
        ->and($second->rows)->toHaveCount(6)
        ->and($second->older)->toBeNull()
        ->and(array_intersect(array_column($first->rows, 'id'), array_column($second->rows, 'id')))->toBe([])
        ->and($secondQueries)->toBe($firstQueries)
        ->and(strtolower($firstSql.$secondSql))->not->toContain('offset')
        ->and(strtolower($firstSql.$secondSql))->not->toContain('count(');
});

it('keeps the word change right across a page boundary', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    foreach (range(1, RevisionHistory::PAGE + 1) as $n) {
        $this->travel(1)->minutes();
        docSave($content->refresh(), $this->author, docWith(docText(str_repeat('word ', $n))));
    }

    $first = RevisionHistory::for($content->id, $this->author);
    $second = RevisionHistory::for($content->id, $this->author, $first->older);

    // The last row of a page still has its predecessor on the next page; the
    // very first revision has none.
    expect($first->rows[RevisionHistory::PAGE - 1]['wordDelta'])->toBe(1)
        ->and($second->rows)->toHaveCount(2)
        ->and($second->rows[1]['wordDelta'])->toBeNull();
});

it('answers null to someone who may not edit the article', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $stranger = User::factory()->create()->assignRole('author');

    expect(RevisionHistory::for($content->id, $stranger))->toBeNull();
});

it('refuses the history to someone who may not edit the article, with a 403', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $stranger = User::factory()->create()->assignRole('author');

    $this->actingAs($stranger)
        ->getJson(route('content.revisions.index', $content->id))
        ->assertForbidden();

    $this->actingAs($this->author)
        ->getJson(route('content.revisions.index', $content->id))
        ->assertOk()
        ->assertJsonStructure(['rows' => [['id', 'kind', 'wordCount', 'wordDelta', 'author', 'createdAt', 'isPublished', 'isWorking']], 'older']);
});

it('reaches the history from the header icon and from the revisions count', function (): void {
    expect(uiComponent('components/editor/editor-header.tsx'))->toMatch("/id: 'history',[^}]*available: true/")
        ->not->toContain('Later (1.5d)')
        ->and(uiComponent('pages/content/edit.tsx'))->toContain("id === 'history'")
        ->toContain('onOpenHistory')
        ->and(uiComponent('components/editor/rail/publish-panel.tsx'))->toContain('onOpenHistory')
        ->not->toContain('Later (1.5d)');
});
