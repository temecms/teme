<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Ada'])->assignRole('author');
});

it('writes each row\'s state after its title, as WordPress\'s post states do, and draws no Status column', function (): void {
    $list = uiComponent('pages/content/index.tsx');

    expect($list)->toContain("draft: 'Draft'")
        ->toContain("review: 'In review'")
        ->toContain("scheduled: 'Scheduled'")
        ->toContain("'Update in review'")
        ->toContain('data-test="status-suffix"')
        ->toContain('` — ${suffix}`')
        ->toContain("'Private'")
        ->toContain('Later (I-1451)')
        ->not->toMatch("/id:\s*'status'/")
        ->not->toContain('StatusBadge');
});

it('labels a row Published, with its published date, only while it is live', function (string $case, bool $live): void {
    $content = addrPublished($this->author, 'Harbour news');
    $input = new ContentActionInput($content->id, (string) $this->author->id);

    if ($case === 'a reverted draft') {
        app(UnpublishContent::class)->execute($input);
    }

    if ($case === 'a live item in the trash') {
        app(TrashContent::class)->execute($input);
    }

    $this->actingAs($this->author)
        ->get(route('content.index', ['type' => 'posts', 'tab' => $case === 'a live item in the trash' ? 'trash' : 'all']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.title', 'Harbour news')
            // Each keeps its published date, and the trash keeps the status:
            // neither alone says whether the row is live.
            ->where('rows.0.publishedAt', fn ($at): bool => $at !== null)
            ->where('rows.0', fn ($row): bool => ($row['status'] === 'published' && ! $row['trashed']) === $live));

    expect(uiComponent('pages/content/index.tsx'))
        ->toContain("const live = row.status === 'published' && !row.trashed;")
        ->toMatch('/live\s*\?\s*\(?row\.publishedAt \?\? row\.updatedAt\)?\s*:\s*row\.updatedAt/')
        ->toMatch("/live\s*\?\s*'Published'\s*:\s*'Last modified'/");
})->with([
    'a live item' => ['a live item', true],
    'a reverted draft, which keeps its published date' => ['a reverted draft', false],
    'a live item in the trash, which keeps its status' => ['a live item in the trash', false],
]);

it('offers View at a live item\'s address and Preview otherwise, as plain links that open a new tab, and says so to screen readers', function (): void {
    expect(uiComponent('pages/content/index.tsx'))
        ->toMatch("/id: 'preview',\s*owner: 'core',\s*label: 'Preview',\s*href: contentPreview\(row\.id\)\.url,\s*newTab: true/")
        ->not->toContain('Later (I-204)')
        ->not->toContain('Later (1.6)')
        ->toMatch("/id: 'view',\s*owner: 'core',\s*label: 'View',\s*href: row\.viewUrl,\s*newTab: true/")
        ->and(uiComponent('components/list/row-actions.tsx'))->toContain('target="_blank"')
        ->toContain('rel="noreferrer"')
        ->toContain(', opens in a new tab')
        ->and(uiComponent('components/list/row-links.tsx'))->toContain('target="_blank"')
        ->toContain(', opens in a new tab');
});

it('asks before deleting one item permanently, saying its revisions go too (I-646)', function (): void {
    expect(uiComponent('pages/content/index.tsx'))
        ->toContain("label: 'Delete permanently'")
        ->toContain('title={`Delete “${titleOf(row)}” permanently?`}')
        ->toContain('description={`“${titleOf(row)}” and all its revisions are deleted. This can’t be undone.`}')
        ->toMatch("/action\('trash', 'Trash', true\)/");
});

it('says "Deleted permanently." after a row is deleted permanently (I-646)', function (): void {
    $content = lifecycleDraft($this->author, 'Old notice');
    app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $this->author->id));

    $this->actingAs($this->author)->from('/admin/posts')
        ->delete(route('content.destroy', ['type' => 'posts', 'content' => $content->id]))
        ->assertRedirect('/admin/posts')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Deleted permanently.']);
});

it('keeps a person\'s other columns when their stored choice still names the Status column', function (): void {
    DB::table('users')->where('id', $this->author->id)->update(['preferences' => json_encode(['lists.post.columns' => ['author', 'status', 'date']])]);

    expect(PreferencesFor::user($this->author->refresh())['lists.post.columns'])->toBe(['author', 'date']);
});
