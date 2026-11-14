<?php

declare(strict_types=1);

use App\Domain\Media\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    // A closure: each import of a file needs a body of its own.
    dlServe(['photos.example' => ['93.184.216.34'], 'inside.example' => ['10.0.0.5']], [
        'photos.example/*' => fn () => Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->draft = lifecycleDraft($this->editor, 'Harbour news');
    $this->edit = "/admin/content/{$this->draft->id}/edit";

    // The document this person's autosave holds.
    $this->saved = fn (): array => json_decode((string) DB::table('content_autosaves')->where('content_id', $this->draft->id)->value('document'), true) ?? [];

    // Waits until what changed last is saved: the save state stops saying otherwise.
    $this->settled = fn (mixed $page): mixed => $page->assertDontSee('Unsaved changes')->assertDontSee('Saving…')->assertSee('Saved');

    // Pastes HTML into the body as a browser does from the clipboard.
    $this->paste = fn (string $html): string => '(() => { const body = document.querySelector(".teme-prose"); body.focus(); const data = new DataTransfer(); data.setData("text/html", '
        .json_encode($html, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        .'); body.dispatchEvent(new ClipboardEvent("paste", { clipboardData: data, bubbles: true, cancelable: true })); return true; })()';

    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get($this->edit)->assertOk();
});

it('keeps a pasted article\'s image in place and unsaved until it is imported, then makes it an Image card with its alt text and caption (I-453)', function (): void {
    $page = cmSignIn($this->editor)->navigate($this->edit)->assertPresent('.teme-prose');

    $page->script(($this->paste)('<p>Gates open at six.</p><figure class="wp-block-image"><img src="https://photos.example/2026/harbour.jpg" alt="The harbour at dawn"><figcaption>Morning</figcaption></figure>'));

    $page->assertSeeIn('[data-test="pasted-images"]', '1 pasted image isn’t in the media library yet')
        ->assertSeeIn('[data-pasted-image]', 'harbour.jpg from photos.example')
        ->assertSeeIn('[data-pasted-image]', 'Alt text: The harbour at dawn')
        ->assertSeeIn('[data-pasted-image]', 'Caption: Morning');
    ($this->settled)($page);

    expect((string) json_encode(($this->saved)()))->toContain('Gates open at six.')
        ->not->toContain('harbour');

    $page->click('[data-pasted-image] button:text-is("Import")')
        ->assertNotPresent('[data-pasted-image]')
        ->assertNotPresent('[data-test="pasted-images"]');
    ($this->settled)($page);

    $media = Media::query()->sole();
    $image = collect(($this->saved)()['content'] ?? [])->firstWhere('type', 'image');

    expect($media->source_url)->toBe('https://photos.example/2026/harbour.jpg')
        ->and($media->uploaded_by)->toBe($this->editor->id)
        ->and($image['attrs']['mediaId'] ?? null)->toBe($media->id)
        ->and($image['attrs']['alt'] ?? null)->toBe('The harbour at dawn')
        ->and($image['attrs']['caption'] ?? null)->toBe('Morning');
});

it('leaves pasted images out of what is saved, and removes them all at once', function (): void {
    $page = cmSignIn($this->editor)->navigate($this->edit)->assertPresent('.teme-prose');

    $page->script(($this->paste)('<p>Boats in the harbour.</p><p><img src="https://photos.example/a.jpg" alt="A boat"><img src="https://photos.example/b.jpg"></p>'));

    $page->assertSeeIn('[data-test="pasted-images"]', '2 pasted images aren’t in the media library yet')
        ->assertSeeIn('[data-test="pasted-images"]', 'Remove all');
    ($this->settled)($page);

    expect((string) json_encode(($this->saved)()))->toContain('Boats in the harbour.')
        ->not->toContain('photos.example');

    $page->click('[data-test="pasted-images"] button:text-is("Remove all")')
        ->assertNotPresent('[data-pasted-image]')
        ->assertNotPresent('[data-test="pasted-images"]');

    expect(Media::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('says why a pasted image can\'t be imported, and offers a failed one again', function (): void {
    $page = cmSignIn($this->editor)->navigate($this->edit)->assertPresent('.teme-prose');

    $page->script(($this->paste)('<p><img src="https://inside.example/a.jpg" alt="Inside"></p><p><img src="data:image/png;base64,iVBORw0KGgo=" alt="Chart"></p>'));

    $page->assertSeeIn('[data-test="pasted-images"]', '2 pasted images aren’t in the media library yet')
        ->assertSee('This image came inside the pasted text, not from a web address, so Teme can’t import it.')
        ->click('[data-test="pasted-images"] button:text-is("Import all")')
        ->assertSee('inside.example is on a private network, so Teme won’t connect to it.')
        ->assertPresent('[data-pasted-image] button:text-is("Try again")');

    expect(Media::query()->count())->toBe(0);
});

it('passes axe with pasted images waiting, in the light and dark schemes, every target 24 pixels or more', function (string $scheme): void {
    lscrPrefer($this->editor, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->editor)->navigate($this->edit)->assertPresent('.teme-prose');
    $page->script(($this->paste)('<p><img src="https://photos.example/a.jpg" alt="A boat"></p><p><img src="data:image/png;base64,iVBORw0KGgo="></p>'));

    // The sidebar folds as the editor arrives; axe reads its labels once they have faded out.
    cmEditorSettled($page->assertPresent('[data-test="pasted-images"]'));

    expect(cmAxeViolations($page))->toBe([]);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with([
    'light' => ['light'],
    'dark' => ['dark'],
]);
