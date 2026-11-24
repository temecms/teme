<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Ada Editor'])->assignRole('editor');
});

/**
 * The editor of this item, settled, with its Categories picker ready.
 */
function eacEditor(User $editor, Content $item): mixed
{
    $page = cmNavigate(cmSignIn($editor)->resize(1280, 900), "/admin/content/{$item->id}/edit");
    cmEditorSettled($page);

    return $page;
}

/**
 * The term IDs the item's working revision holds.
 *
 * @return list<string>
 */
function eacSavedTerms(Content $item): array
{
    $revision = Content::query()->whereKey($item->id)->value('working_revision_id');

    return json_decode((string) DB::table('content_revisions')->where('id', $revision)->value('terms'), true);
}

it('adds a category with Enter, focuses its checked row and keeps it when the article is saved', function (): void {
    $item = lifecycleDraft($this->editor, 'Bridge notes');
    $page = eacEditor($this->editor, $item);

    $page->click('[data-test="rail-terms-category"] button:text-is("+ Add")');
    $name = '[data-test="rail-terms-category"] label:text-is("Name") + input';
    $page->type($name, 'Bridges');
    $page->page()->locator($name)->press('Enter');

    cmWaitUntil($page, <<<'JS'
        (() => {
            const label = [...document.querySelectorAll('[data-test="rail-terms-category"] label')]
                .find((item) => item.textContent?.trim() === 'Bridges');
            const checkbox = label ? document.getElementById(label.htmlFor) : null;

            return document.querySelector('[data-test="rail-terms-category"] label[for$="-name"] + input') === null
                && checkbox !== null
                && document.activeElement === checkbox
                && checkbox.getAttribute('aria-checked') === 'true';
        })()
        JS);

    $created = DB::table('terms')->where('taxonomy', 'category')->where('name', 'Bridges')->value('id');

    expect($page->script('() => location.pathname'))->toBe("/admin/content/{$item->id}/edit")
        ->and($created)->not->toBeNull()
        ->and(eacSavedTerms($item))->not->toContain($created);

    $page->keys('.teme-prose', 'Control+s');
    cmWaitUntil($page, "document.querySelector('[data-test=\"save-state\"]')?.dataset.state === 'saved'");

    expect(eacSavedTerms($item))->toContain($created);
    $page->assertNoJavaScriptErrors();
});

it('keeps the add area open and creates no category when Enter is pressed on an empty name', function (): void {
    $item = lifecycleDraft($this->editor, 'Bridge notes');
    $page = eacEditor($this->editor, $item);

    $page->click('[data-test="rail-terms-category"] button:text-is("+ Add")');
    $name = '[data-test="rail-terms-category"] label:text-is("Name") + input';
    $page->page()->locator($name)->press('Enter');

    $page->assertPresent($name)
        ->assertNoJavaScriptErrors();

    expect(DB::table('terms')->where('taxonomy', 'category')->count())->toBe(0);
});
