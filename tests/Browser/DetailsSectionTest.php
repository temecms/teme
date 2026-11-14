<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Backups\ScreenRecords;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const DS_TAGLINE = '01K9F1E1D0000000000000DS01';
const DS_NOTE = '01K9F1E1D0000000000000DS02';
const DS_RUNTIME = '01K9F1E1D0000000000000DS03';
const DS_RATING = '01K9F1E1D0000000000000DS04';
const DS_IN_CINEMAS = '01K9F1E1D0000000000000DS05';
const DS_TRAILER = '01K9F1E1D0000000000000DS06';
const DS_RELEASE = '01K9F1E1D0000000000000DS07';
const DS_PREMIERE = '01K9F1E1D0000000000000DS08';
const DS_POSTER = '01K9F1E1D0000000000000DS09';
const DS_SEQUEL = '01K9F1E1D0000000000000DS0A';

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    ScreenRecords::settings(['general' => ['timezone' => 'Africa/Lagos']]);
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');

    StoredFieldRows::movieType();
    // Published before its fields exist, so Required doesn't hold it back.
    $this->arrival = lifecycleDraft($this->editor, 'Arrival', 'movie');
    app(PublishRevision::class)->execute(new PublishRevisionInput($this->arrival->id, (string) $this->arrival->working_revision_id, (string) $this->editor->id));
    $fields = [
        [DS_TAGLINE, 'tagline', ['label' => 'Tagline']],
        [DS_NOTE, 'note', ['label' => 'Note', 'fieldType' => 'textarea']],
        [DS_RUNTIME, 'runtime', ['label' => 'Runtime', 'fieldType' => 'number', 'help' => 'In minutes.']],
        [DS_RATING, 'rating', ['label' => 'Rating', 'fieldType' => 'select', 'settings' => ['choices' => [['value' => 'g', 'label' => 'G'], ['value' => 'pg', 'label' => 'PG']]]]],
        [DS_IN_CINEMAS, 'in_cinemas', ['label' => 'In cinemas', 'fieldType' => 'boolean']],
        [DS_TRAILER, 'trailer_url', ['label' => 'Trailer', 'fieldType' => 'url']],
        [DS_RELEASE, 'release_date', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true], 'required' => true]],
        [DS_PREMIERE, 'premiere', ['label' => 'Premiere', 'fieldType' => 'datetime']],
        [DS_POSTER, 'poster', ['label' => 'Poster', 'fieldType' => 'media']],
        [DS_SEQUEL, 'sequel', ['label' => 'Sequel', 'fieldType' => 'entry']],
    ];

    foreach ($fields as $position => [$key, $handle, $overrides]) {
        StoredFieldRows::store(StoredFieldRows::entry($key, $handle, $overrides), $position + 1);
    }

    $this->image = pageImage($this->editor, 1200, 630);
    $this->movie = lifecycleDraft($this->editor, 'Story of your life', 'movie');
    $this->edit = "/admin/content/{$this->movie->id}/edit";

    // The working revision's fields, as the database holds them.
    $this->stored = fn (): array => (array) ContentRevision::query()->findOrFail(Content::query()->findOrFail($this->movie->id)->working_revision_id)->fields;

    // Saves with the rail's Save draft, and waits until the working revision has moved.
    $this->save = function (mixed $page): void {
        $before = Content::query()->findOrFail($this->movie->id)->working_revision_id;
        $page->click('[aria-label="Movie settings"] button:text-is("Save draft")');
        cmWaitUntil($page, "document.querySelector('[data-test=\"save-state\"]')?.dataset.state === 'saved'");

        for ($tries = 0; $tries < 100 && Content::query()->findOrFail($this->movie->id)->working_revision_id === $before; $tries++) {
            usleep(100_000);
        }

        expect(Content::query()->findOrFail($this->movie->id)->working_revision_id)->not->toBe($before);
    };

    // Chooses the one image in the library for the poster.
    $this->choosePoster = function (mixed $page): mixed {
        $page->click('#editor-field-poster-choose');
        shellSettled($page, '[data-slot="dialog-content"]')
            ->click('[role="dialog"] [role="option"]')
            ->click('[data-test="media-modal-use"]');

        return cmWaitUntil($page, "document.querySelector('[data-slot=\"dialog-content\"]') === null && document.getElementById('editor-field-poster-alt') !== null");
    };

    // Fills every field but the poster's alt text and decorative box, and the release date when asked.
    $this->fill = function (mixed $page, bool $release = true): mixed {
        $page->type('#editor-field-tagline', 'First contact')
            ->type('#editor-field-note', "Line one\nLine two")
            ->type('#editor-field-runtime', '116')
            ->click('#editor-field-rating')
            ->click('[role="option"] >> text="PG"');
        cmWaitUntil($page, "document.querySelector('[role=\"listbox\"]') === null");
        $page->click('#editor-field-in_cinemas')
            ->type('#editor-field-trailer_url', 'https://example.com/arrival');

        if ($release) {
            $page->type('#editor-field-release_date', '2016-11-11');
        }

        $page->type('#editor-field-premiere-date', '2016-09-01')
            ->type('#editor-field-premiere-time', '19:30');
        ($this->choosePoster)($page);
        $page->type('#editor-field-sequel', 'Arri')
            ->click('[data-test="entry-option"]:has-text("Arrival")');

        return cmWaitUntil($page, "(document.querySelector('[data-test=\"details-section\"]')?.innerText ?? '').includes('Arrival')");
    };

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

it('draws Details under the body with every field, fills each, saves, and finds every value after a reload', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1440, 900), $this->edit));

    // The section sits after the body and before Search and social.
    expect($page->script('() => { const d = document.querySelector("[data-test=details-section]"); const b = document.querySelector("section[aria-labelledby=editor-body-label]"); const s = document.querySelector("[data-test=search-toggle]"); return d !== null && b !== null && (b.compareDocumentPosition(d) & 4) !== 0 && (s === null || (d.compareDocumentPosition(s) & 4) !== 0); }'))->toBeTrue()
        ->and($page->script('() => [...document.querySelectorAll("[data-test=details-row]")].length'))->toBe(10);

    ($this->fill)($page);
    $page->type('#editor-field-poster-alt', 'A shell-shaped ship over a meadow');
    ($this->save)($page);

    expect(($this->stored)())->toBe([
        DS_TAGLINE => 'First contact',
        DS_NOTE => "Line one\nLine two",
        DS_RUNTIME => 116,
        DS_RATING => 'pg',
        DS_IN_CINEMAS => true,
        DS_TRAILER => 'https://example.com/arrival',
        DS_RELEASE => '2016-11-11',
        DS_PREMIERE => '2016-09-01T18:30:00+00:00',
        DS_POSTER => ['id' => $this->image->id, 'alt' => 'A shell-shaped ship over a meadow', 'decorative' => false],
        DS_SEQUEL => $this->arrival->id,
    ]);

    cmEditorSettled(cmNavigate($page, $this->edit));
    cmWaitUntil($page, "document.getElementById('editor-field-poster-alt') !== null");

    expect($page->script('() => ({
        tagline: document.getElementById("editor-field-tagline").value,
        note: document.getElementById("editor-field-note").value,
        runtime: document.getElementById("editor-field-runtime").value,
        rating: document.getElementById("editor-field-rating").innerText.trim(),
        inCinemas: document.getElementById("editor-field-in_cinemas").getAttribute("aria-checked"),
        trailer: document.getElementById("editor-field-trailer_url").value,
        release: document.getElementById("editor-field-release_date").value,
        premiereDate: document.getElementById("editor-field-premiere-date").value,
        premiereTime: document.getElementById("editor-field-premiere-time").value,
        alt: document.getElementById("editor-field-poster-alt").value,
        poster: document.querySelector("[data-test=details-section] img") !== null,
        sequel: document.querySelector("[data-test=details-section]").innerText.includes("Arrival"),
    })'))->toBe([
        'tagline' => 'First contact',
        'note' => "Line one\nLine two",
        'runtime' => '116',
        'rating' => 'PG',
        'inCinemas' => 'true',
        'trailer' => 'https://example.com/arrival',
        'release' => '2016-11-11',
        'premiereDate' => '2016-09-01',
        'premiereTime' => '19:30',
        'alt' => 'A shell-shaped ship over a meadow',
        'poster' => true,
        'sequel' => true,
    ]);

    cmNoJavaScriptErrors($page);
});

it('stores a decorative poster with no alt text, and draws it so after a reload', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1440, 900), $this->edit));
    ($this->choosePoster)($page);
    $page->click('#editor-field-poster-decorative');
    cmWaitUntil($page, "document.getElementById('editor-field-poster-alt').disabled === true");
    ($this->save)($page);

    // A media value stores no alt text as null, as the featured image does.
    expect(($this->stored)()[DS_POSTER])->toBe(['id' => $this->image->id, 'alt' => null, 'decorative' => true]);

    cmEditorSettled(cmNavigate($page, $this->edit));
    cmWaitUntil($page, "document.getElementById('editor-field-poster-decorative')?.getAttribute('aria-checked') === 'true' && document.getElementById('editor-field-poster-alt').disabled === true");

    cmNoJavaScriptErrors($page);
});

it('says under a required field why the movie can’t be published, and clears it when filled', function (): void {
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1440, 900), $this->edit))
        ->click('[aria-label="Movie settings"] button:text-is("Publish")');

    // A first publication asks first; its Publish sends it.
    cmWaitUntil($page, "document.querySelector('[role=\"dialog\"] button') !== null");
    $page->click('[role="dialog"] button:text-is("Publish")');
    cmWaitUntil($page, "document.getElementById('editor-field-release_date-error') !== null");

    expect($page->script('() => document.getElementById("editor-field-release_date-error").innerText'))->toBe('Fill in “Release date” to publish this movie.')
        ->and($page->script('() => document.getElementById("editor-field-release_date").getAttribute("aria-invalid")'))->toBe('true')
        ->and($page->script('() => document.getElementById("editor-field-release_date").getAttribute("aria-describedby")'))->toContain('editor-field-release_date-error')
        ->and(Content::query()->findOrFail($this->movie->id)->status)->toBe('draft');

    $page->type('#editor-field-release_date', '2016-11-11');
    cmWaitUntil($page, "document.getElementById('editor-field-release_date-error') === null");

    cmNoJavaScriptErrors($page);
});

it('draws no Details on a post, which has only core’s fields', function (): void {
    $post = lifecycleDraft($this->editor, 'Harbour news');
    $this->actingAs($this->editor)->get("/admin/content/{$post->id}/edit")->assertOk();

    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor), "/admin/content/{$post->id}/edit"));

    expect(cmAnswer($page, '() => document.querySelector("[data-test=details-section]") === null'))->toBeTrue();
    cmNoJavaScriptErrors($page);
});

it('passes axe on a movie with every field, filled and with an error', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);
    $page = cmEditorSettled(cmNavigate(cmSignIn($this->editor)->resize(1440, 900), $this->edit));
    ($this->fill)($page, release: false);

    expect(cmAxeViolations($page))->toBe([]);

    $page->click('[aria-label="Movie settings"] button:text-is("Publish")');
    cmWaitUntil($page, "document.querySelector('[role=\"dialog\"] button') !== null");
    $page->click('[role="dialog"] button:text-is("Publish")');
    cmWaitUntil($page, "document.getElementById('editor-field-release_date-error') !== null && document.querySelector('[role=\"dialog\"]') === null");

    expect(cmAxeViolations($page))->toBe([]);
    cmNoJavaScriptErrors($page);
})->with(['light', 'dark']);

it('draws Details at 375 pixels with nothing scrolling sideways or cut off', function (): void {
    $page = cmNavigate(cmSignIn($this->editor)->resize(375, 812), $this->edit);
    // On a phone the menu is a drawer, so cmEditorSettled's folded menu never comes.
    cmWaitUntil($page, "document.querySelector('.teme-prose') !== null");
    ($this->fill)($page);
    $page->script('() => document.querySelector("[data-test=details-section]").scrollIntoView()');

    expect($page->script('() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth)'))->toBe(0)
        ->and($page->script('() => [...document.querySelectorAll("[data-test=details-section] input, [data-test=details-section] button, [data-test=details-section] textarea")].filter((element) => { const box = element.getBoundingClientRect(); return box.width > 0 && (box.left < 0 || box.right > window.innerWidth); }).map((element) => element.id || element.outerHTML.slice(0, 80))'))->toBe([])
        ->and(cmAxeViolations($page))->toBe([]);
    cmNoJavaScriptErrors($page);
});
