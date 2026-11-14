<?php

declare(strict_types=1);

use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\Models\AiActivity;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');

    // The warm-up below signs in through actingAs(), and the browser must not inherit it.
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->author = User::factory()->create(['name' => 'Ada Author'])->assignRole('author');
    $this->media = altImage($this->author);
    $this->fake = altReady();
    $this->field = fn (string $name): string => '#attachment-'.$this->media->id.'-'.$name;

    // Warm the library before the browser's first visit (I-638).
    $this->actingAs($this->author)->get('/admin/media')->assertOk();

    // Opens the image's details beside the library and waits for them to settle before anything clicks inside them.
    $this->open = fn (mixed $page): mixed => shellSettled($page->click('[role="option"]')->waitForText('Suggest alt text'), '[data-test="media-detail"]');

    // axe on the suggestion's own block, target sizes included: the drawer around it is swept with the rest of Media (I-440).
    $this->axe = fn (mixed $page): array => cmScriptArray($page, <<<'JS'
        async () => {
            const run = await window.axe.run(document.querySelector('[data-test="alt-text-suggestion"]'), { rules: { 'target-size': { enabled: true } } });

            return run.violations.map((violation) => violation.id);
        }
        JS);
});

it('suggests alt text in the library’s drawer, shows it beside what the field holds, and Use saves it there and then (I-321, I-317)', function (string $scheme): void {
    lscrPrefer($this->author, ['appearance.mode' => $scheme]);
    $this->fake->answer(altAnswer('Fishing boats moored at dawn.'));

    $page = ($this->open)(cmSignIn($this->author)->navigate('/admin/media'))
        ->assertSee('The image is sent to Fake AI.')
        ->type(($this->field)('caption'), 'Typed, not saved')
        ->click('[data-test="alt-text-suggest"]')
        ->assertSeeIn('[data-test="alt-text-card"]', 'Sent to Fake AI')
        ->assertSeeIn('[data-test="alt-text-card"] ins', 'Fishing');

    expect(($this->axe)($page))->toBe([]);

    $page->click('[data-test="ai-use"]')
        ->assertSee('Suggested alt text saved.')
        ->assertNotPresent('[data-test="alt-text-card"]')
        ->assertValue(($this->field)('alt'), 'Fishing boats moored at dawn.')
        ->assertValue(($this->field)('caption'), 'Typed, not saved');

    // Use saved the alt text and left the caption alone: the caption holds what leaving its field saved.
    expect($this->media->refresh()->only(['alt', 'caption']))->toBe(['alt' => 'Fishing boats moored at dawn.', 'caption' => 'Typed, not saved'])
        ->and(AiActivity::query()->sole()->outcome)->toBe(AiOutcome::Applied);
})->with(['light', 'dark']);

it('asks from the palette’s AI section for the image whose details are open, and offers nothing with none open (I-1318)', function (): void {
    $this->fake->answer(altAnswer('Fishing boats moored at dawn.'));

    $page = cmSignIn($this->author)->navigate('/admin/media')->keys('[aria-label="Search media"]', 'Control+k');
    shellSettled($page, '[data-slot="dialog-content"]')
        ->assertSee('Go to')
        ->assertDontSee('Suggest alt text for this image')
        ->keys('[aria-label="Search, go to, or do"]', 'Escape')
        ->assertNotPresent('[data-slot="dialog-content"]');

    ($this->open)($page)->keys(($this->field)('title'), 'Control+k');
    shellSettled($page, '[data-slot="dialog-content"]')
        ->click('Suggest alt text for this image')
        ->assertSeeIn('[data-test="alt-text-card"]', 'Sent to Fake AI')
        ->assertSeeIn('[data-test="alt-text-card"] ins', 'Fishing');
});

it('says plainly when AI fails, and Discard leaves the alt text and the form as they were', function (): void {
    $this->fake->fail(AiFailure::refused())->answer(altAnswer('Fishing boats moored at dawn.'));

    ($this->open)(cmSignIn($this->author)->navigate('/admin/media'))
        ->type(($this->field)('caption'), 'Typed, not saved')
        ->click('[data-test="alt-text-suggest"]')
        ->waitForText('The AI service declined to answer.')
        ->click('[data-test="ai-dismiss"]')
        ->assertNotPresent('[data-test="alt-text-card"]')
        ->click('[data-test="alt-text-suggest"]')
        ->assertSeeIn('[data-test="alt-text-card"]', 'Sent to Fake AI')
        ->assertSeeIn('[data-test="alt-text-card"] ins', 'Fishing')
        ->click('[data-test="ai-discard"]')
        ->assertNotPresent('[data-test="alt-text-card"]')
        ->assertValue(($this->field)('alt'), '')
        ->assertValue(($this->field)('caption'), 'Typed, not saved');

    // Discard wrote nothing: the caption holds what leaving its field saved.
    expect($this->media->refresh()->only(['alt', 'caption']))->toBe(['alt' => null, 'caption' => 'Typed, not saved'])
        ->and(AiActivity::query()->pluck('outcome')->map(fn (AiOutcome $outcome): string => $outcome->value)->sort()->values()->all())->toBe(['discarded', 'refused']);
});

it('suggests alt text in the editor’s media window too, and a field saved afterwards keeps it', function (): void {
    $this->fake->answer(altAnswer('Fishing boats moored at dawn.'));
    $edit = '/admin/content/'.lifecycleDraft($this->author, 'Harbour news')->id.'/edit';
    $this->actingAs($this->author)->get($edit)->assertOk();

    $page = cmSignIn($this->author)->navigate($edit)->click('#editor-featured-choose');
    shellSettled($page, '[data-slot="dialog-content"]')
        ->click('[data-slot="dialog-content"] [role="option"]')
        ->waitForText('Suggest alt text')
        ->click('[data-test="alt-text-suggest"]')
        ->assertSeeIn('[data-test="alt-text-card"]', 'Sent to Fake AI')
        ->click('[data-test="ai-use"]')
        ->assertSee('Suggested alt text saved.')
        ->assertValue(($this->field)('alt'), 'Fishing boats moored at dawn.')
        // The window saves a field when it's left, from its own copy of the image's details.
        ->type(($this->field)('caption'), 'Boats at dawn')
        ->click(($this->field)('title'))
        ->wait(2);

    expect($this->media->refresh()->only(['alt', 'caption']))->toBe(['alt' => 'Fishing boats moored at dawn.', 'caption' => 'Boats at dawn']);
});
