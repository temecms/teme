<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Operations\AddField;
use App\Domain\Fields\Operations\Input\AddFieldInput;
use App\Domain\Fields\Stored\FieldDefinition;
use App\Domain\Fields\Stored\StoredFields;
use App\Models\User;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const FBB_MOOD = '01K9F1E1D0000000000000MD07';

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built script and styles.
    $this->withVite();
    $this->admin = ctAdmin();
    $this->editor = User::factory()->create()->assignRole('editor');

    // Each request starts afresh, as on a real server.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    ctCreate($this->admin, ['key' => 'movie', 'singular' => 'Movie', 'plural' => 'Movies', 'segment' => 'movies', 'icon' => 'film', 'taxonomies' => [], 'supports' => ['seo', 'excerpt']]);
    $this->rating = fbbAdd($this->admin, 'rating', ['label' => 'Rating', 'fieldType' => 'select', 'search' => true, 'settings' => ['choices' => [['value' => 'g', 'label' => 'G'], ['value' => 'pg', 'label' => 'PG'], ['value' => 'pg-13', 'label' => 'PG-13']]]]);
    $this->trailer = fbbAdd($this->admin, 'trailer_url', ['label' => 'Trailer URL', 'fieldType' => 'url']);

    $this->seen = fn (mixed $page, string $text): mixed => cmWaitUntil($page, 'document.body.innerText.includes('.json_encode($text).')');
    // How far the page scrolls sideways: none. The kept scrollbar gutter makes the raw difference negative.
    $this->sideways = fn (mixed $page): int => (int) $page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)');
    // The sheet or dialog's own animations, never its subtree's (.ai/rules/builders.md).
    $this->settled = 'document.querySelector(\'[role="dialog"]\') !== null && document.querySelector(\'[role="dialog"]\').getAnimations().every((a) => a.playState !== "running")';
    $this->closed = 'document.querySelector(\'[role="dialog"]\') === null';
    $this->order = fn (mixed $page): array => cmScriptArray($page, '() => [...document.querySelectorAll(\'tr[data-test^="field-row-"]\')].map((row) => row.dataset.test.slice(10))');

    // Warm the screens before the browser's first visit.
    $this->actingAs($this->admin)->get('/admin/types/movie/fields')->assertOk();
});

/**
 * A field added to movies through AddField, as the sheet adds one.
 *
 * @param  array<string, mixed>  $overrides
 */
function fbbAdd(User $admin, string $handle, array $overrides): string
{
    return app(AddField::class)->execute(new AddFieldInput('movie', FieldDefinition::fromArray(StoredFieldRows::entry('', $handle, $overrides)), (string) $admin->id));
}

/**
 * A movie draft holding these values, saved as the editor saves it.
 *
 * @param  array<string, mixed>  $fields
 */
function fbbMovie(User $author, string $title, array $fields): Content
{
    $movie = lifecycleDraft($author, $title, 'movie')->refresh();
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, $title, ['type' => 'doc', 'content' => []], $fields, $movie->working_revision_id, (string) $author->id));

    return $movie->refresh();
}

it('adds Runtime in the side sheet, and the editor shows it in Details at once', function (): void {
    $movie = fbbMovie($this->editor, 'Arrival', []);
    $page = cmSignIn($this->admin)->navigate('/admin/types/movie/fields');
    ($this->seen)($page, 'Movie fields');

    $page->click('button:text-is("Add field")');
    cmWaitUntil($page, $this->settled);
    $page->type('#field-label', 'Runtime');
    cmWaitUntil($page, 'document.querySelector("#field-handle").value === "runtime"');

    $page->click('[role="dialog"] label:has(input[name="fieldType"][value="number"])')
        ->type('#field-help', 'In minutes.')
        ->click('[role="dialog"] button[type="submit"]:text-is("Add field")');
    cmWaitUntil($page, $this->closed);
    ($this->seen)($page, 'Added Runtime.');

    expect(last(($this->order)($page)))->toBe('runtime');

    cmNavigate($page, "/admin/content/{$movie->id}/edit");
    cmWaitUntil($page, 'document.querySelector("#editor-field-runtime") !== null');
});

it('edits Rating’s choices and labels, its type shown with the reason it can’t change', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/types/movie/fields');
    ($this->seen)($page, 'Movie fields');

    $page->click('[data-test="field-row-rating"] button:text-is("Edit")');
    cmWaitUntil($page, $this->settled);
    ($this->seen)($page, 'Can’t change: stored values would be read as another kind. To change it, add a new field and remove this one.');

    expect($page->script('() => [...document.querySelectorAll(\'[role="dialog"] input[name$="[value]"]\')].map((input) => input.readOnly)'))->toBe([true, true, true]);

    $page->click('[role="dialog"] button:text-is("Add a choice")');
    cmWaitUntil($page, 'document.querySelectorAll(\'[role="dialog"] input[name$="[value]"]\').length === 4');
    $page->type('[role="dialog"] input[name="settings[choices][3][value]"]', 'r')
        ->type('[role="dialog"] input[name="settings[choices][3][label]"]', 'R')
        ->click('[role="dialog"] button[type="submit"]:text-is("Save field")');
    cmWaitUntil($page, $this->closed);
    ($this->seen)($page, 'Saved Rating.');

    expect(app(FieldRegistry::class)->find($this->rating)?->options)->toBe(['' => 'None', 'g' => 'G', 'pg' => 'PG', 'pg-13' => 'PG-13', 'r' => 'R']);
});

it('reorders by Move up and by dragging, each saved at once', function (): void {
    fbbAdd($this->admin, 'runtime', ['label' => 'Runtime', 'fieldType' => 'number']);
    $page = cmSignIn($this->admin)->navigate('/admin/types/movie/fields');
    ($this->seen)($page, 'Movie fields');

    $page->click('[data-test="field-row-runtime"] button:text-is("Move up")');
    cmWaitUntil($page, 'document.querySelectorAll(\'tr[data-test^="field-row-"]\')[1]?.dataset.test === "field-row-runtime"');
    cmWaitUntil($page, 'document.activeElement?.dataset.move?.endsWith(":up") === true');

    expect(($this->order)($page))->toBe(['rating', 'runtime', 'trailer_url']);

    $page->drag('[data-test="field-row-rating"] [data-test="field-grip"]', '[data-test="field-row-trailer_url"]');
    cmWaitUntil($page, 'document.querySelectorAll(\'tr[data-test^="field-row-"]\')[2]?.dataset.test === "field-row-rating"');

    cmNavigate($page, '/admin/types/movie/fields');
    ($this->seen)($page, 'Movie fields');

    expect(($this->order)($page))->toBe(['runtime', 'trailer_url', 'rating']);
});

it('removes Trailer URL after asking, keeps its value, and restores it', function (): void {
    $movie = fbbMovie($this->editor, 'Arrival', [$this->trailer => 'https://a.test/trailer']);
    $page = cmSignIn($this->admin)->navigate('/admin/types/movie/fields');
    ($this->seen)($page, 'Movie fields');

    $page->click('[data-test="field-row-trailer_url"] button:text-is("Remove")');
    cmWaitUntil($page, $this->settled);
    ($this->seen)($page, 'Remove Trailer URL?');
    ($this->seen)($page, 'The values 1 movie hold stay stored');

    $page->click('[role="dialog"] button:text-is("Remove field")');
    cmWaitUntil($page, $this->closed);
    ($this->seen)($page, 'Removed Trailer URL.');
    cmWaitUntil($page, 'document.querySelector(\'[data-test="removed-row-trailer_url"]\') !== null');
    ($this->seen)($page, '1 movie holds a value');

    cmNavigate($page, "/admin/content/{$movie->id}/edit");
    cmWaitUntil($page, 'document.querySelector("#editor-field-rating") !== null');

    expect($page->script('() => document.querySelector("#editor-field-trailer_url")'))->toBeNull();

    cmNavigate($page, '/admin/types/movie/fields');
    $page->click('[data-test="removed-row-trailer_url"] button:text-is("Restore")');
    ($this->seen)($page, 'Restored Trailer URL.');

    expect(last(($this->order)($page)))->toBe('trailer_url');

    cmNavigate($page, "/admin/content/{$movie->id}/edit");
    cmWaitUntil($page, 'document.querySelector("#editor-field-trailer_url")?.value === "https://a.test/trailer"');
});

it('turns a date field date only while no movie holds a value, and not after', function (): void {
    $release = fbbAdd($this->admin, 'release_date', ['label' => 'Release date', 'fieldType' => 'datetime']);
    $page = cmSignIn($this->admin)->navigate('/admin/types/movie/fields');
    ($this->seen)($page, 'Movie fields');

    $page->click('[data-test="field-row-release_date"] button:text-is("Edit")');
    cmWaitUntil($page, $this->settled);
    ($this->seen)($page, 'Can’t change once a movie holds a value.');

    expect($page->script('() => document.querySelector("#field-ask-date").disabled'))->toBeFalse();

    $page->click('#field-ask-date')->click('[role="dialog"] button[type="submit"]:text-is("Save field")');
    ($this->seen)($page, 'Saved Release date.');
    cmWaitUntil($page, $this->closed);

    fbbMovie($this->editor, 'Arrival', [$release => '2016-11-11']);
    cmNavigate($page, '/admin/types/movie/fields');
    ($this->seen)($page, 'Date only');
    $page->click('[data-test="field-row-release_date"] button:text-is("Edit")');
    cmWaitUntil($page, $this->settled);

    expect($page->script('() => [document.querySelector("#field-ask-date").disabled, document.querySelector("#field-ask-datetime").disabled, document.querySelector("#field-ask-date").getAttribute("aria-checked") === "true"]'))->toBe([true, true, true]);
});

it('passes axe on the Fields page, the add sheet and the edit sheet', function (string $scheme): void {
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);
    fbbMovie($this->editor, 'Arrival', [$this->trailer => 'https://a.test/trailer']);
    $page = cmSignIn($this->admin)->navigate('/admin/types/movie/fields');
    ($this->seen)($page, 'Movie fields');

    expect(cmAxeViolations($page))->toBe([]);

    $page->click('button:text-is("Add field")');
    cmWaitUntil($page, $this->settled);
    $page->click('[role="dialog"] label:has(input[name="fieldType"][value="datetime"])');
    ($this->seen)($page, 'Ask for');

    expect(cmAxeViolations($page))->toBe([]);

    $page->click('[role="dialog"] button:text-is("Cancel")');
    cmWaitUntil($page, $this->closed);
    $page->click('[data-test="field-row-rating"] button:text-is("Edit")');
    cmWaitUntil($page, $this->settled);

    expect(cmAxeViolations($page))->toBe([]);
})->with(['light', 'dark']);

it('draws the Fields page and the sheet at 375 pixels with nothing scrolling sideways', function (): void {
    $page = cmSignIn($this->admin)->resize(375, 812)->navigate('/admin/types/movie/fields');
    ($this->seen)($page, 'Movie fields');

    expect(($this->sideways)($page))->toBe(0)
        ->and(cmAxeViolations($page))->toBe([]);

    $page->click('button:text-is("Add field")');
    cmWaitUntil($page, $this->settled);
    $page->click('[role="dialog"] label:has(input[name="fieldType"][value="select"])');
    cmWaitUntil($page, 'document.querySelector(\'[role="dialog"] input[name="settings[choices][0][value]"]\') !== null');

    expect(($this->sideways)($page))->toBe(0)
        ->and($page->script('() => [...document.querySelectorAll(\'[role="dialog"] input, [role="dialog"] button\')].every((el) => { const r = el.getBoundingClientRect(); return r.width === 0 || (r.left >= 0 && r.right <= window.innerWidth); })'))->toBeTrue()
        ->and(cmAxeViolations($page))->toBe([]);
});

it('shows a locked site’s Fields page with nothing to change', function (): void {
    config(['teme.types_locked' => true]);
    $page = cmSignIn($this->admin)->navigate('/admin/types/movie/fields');
    ($this->seen)($page, 'The types are locked on this site, so fields can’t be added or changed here.');

    expect($page->script('() => [...document.querySelectorAll("main button")].map((b) => b.textContent.trim()).filter((t) => ["Add field", "Edit", "Move up", "Move down", "Remove"].includes(t))'))->toBe([]);
});

it('opens the Fields page from Manage fields on Movies’ page and on Posts’ page', function (): void {
    $page = cmSignIn($this->admin)->navigate('/admin/types/movie');
    ($this->seen)($page, '2 fields of your own:');

    $page->click('a:text-is("Manage fields")');
    cmWaitUntil($page, 'location.pathname === "/admin/types/movie/fields"');

    cmNavigate($page, '/admin/types/post');
    ($this->seen)($page, 'No fields of your own yet.');
    $page->click('a:text-is("Manage fields")');
    cmWaitUntil($page, 'location.pathname === "/admin/types/post/fields"');
    ($this->seen)($page, 'Post fields');
});

it('saves the pictures the owner looks at', function (): void {
    fbbAdd($this->admin, 'release_date', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true], 'required' => true]);
    fbbAdd($this->admin, 'runtime', ['label' => 'Runtime', 'fieldType' => 'number', 'help' => 'In minutes.']);
    fbbAdd($this->admin, 'poster', ['label' => 'Poster', 'fieldType' => 'media']);
    fbbMovie($this->editor, 'Arrival', [$this->trailer => 'https://a.test/trailer']);

    $page = cmSignIn($this->admin)->resize(1440, 900)->navigate('/admin/types/movie/fields');
    ($this->seen)($page, 'Movie fields');
    $page->screenshot(true, 'fields-page-five');

    $page->click('button:text-is("Add field")');
    cmWaitUntil($page, $this->settled);
    $page->type('#field-label', 'Premiere')->click('[role="dialog"] label:has(input[name="fieldType"][value="datetime"])');
    ($this->seen)($page, 'Ask for');
    $page->screenshot(true, 'fields-add-sheet-date');
    $page->click('[role="dialog"] button:text-is("Cancel")');
    cmWaitUntil($page, $this->closed);

    $page->click('[data-test="field-row-rating"] button:text-is("Edit")');
    cmWaitUntil($page, $this->settled);
    $page->screenshot(true, 'fields-edit-sheet-choice');
    $page->click('[role="dialog"] button:text-is("Cancel")');
    cmWaitUntil($page, $this->closed);

    $page->click('[data-test="field-row-trailer_url"] button:text-is("Remove")');
    cmWaitUntil($page, $this->settled);
    $page->click('[role="dialog"] button:text-is("Remove field")');
    cmWaitUntil($page, $this->closed);
    DB::table(StoredFields::TABLE)->insert(['key' => FBB_MOOD, 'content_type' => 'movie', 'handle' => 'mood', 'position' => 9, 'definition' => '{"fieldType":"text"}', 'created_at' => now(), 'updated_at' => now()]);
    app(StoredFields::class)->remember();
    cmNavigate($page, '/admin/types/movie/fields');
    ($this->seen)($page, 'The saved field “mood” can’t be used: [label] is missing.');
    $page->screenshot(true, 'fields-removed-unreadable');

    cmNavigate($page->resize(375, 812), '/admin/types/movie/fields');
    ($this->seen)($page, 'Movie fields');
    $page->screenshot(true, 'fields-page-375');

    lscrPrefer($this->admin, ['appearance.mode' => 'dark']);
    $page = cmSignIn($this->admin)->resize(1440, 900)->navigate('/admin/types/movie/fields');
    ($this->seen)($page, 'Movie fields');
    $page->click('button:text-is("Add field")');
    cmWaitUntil($page, $this->settled);
    $page->type('#field-label', 'Premiere')->click('[role="dialog"] label:has(input[name="fieldType"][value="datetime"])');
    ($this->seen)($page, 'Ask for');
    $page->screenshot(true, 'fields-add-sheet-date-dark');
    $page->click('[role="dialog"] button:text-is("Cancel")');
    cmWaitUntil($page, $this->closed);
    $page->click('[data-test="field-row-rating"] button:text-is("Edit")');
    cmWaitUntil($page, $this->settled);
    $page->screenshot(true, 'fields-edit-sheet-choice-dark');
});
