<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs the built styles and the admin's script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');

    // Each request starts afresh, as on a real server (TwoEditorsTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->audio = avUpload($this->editor, avAudioFile(), 'Tide report.mp3');

    // Waits until an open dialog has finished arriving: its content's and overlay's own animations.
    $this->arrived = "(() => { const parts = [...document.querySelectorAll('[data-slot=\"dialog-content\"], [data-slot=\"dialog-overlay\"]')]; return parts.length === 0 ? -1 : parts.flatMap((element) => element.getAnimations()).filter((animation) => animation.playState === 'running').length; })()";

    $this->publishBoth = function (): void {
        $video = avUpload($this->editor, avVideoFile(), 'Harbour dawn.mp4');
        $captions = avUpload($this->editor, avCaptionsFile(), 'Harbour dawn.vtt');
        $poster = pageImage($this->editor, 1600, 900);

        // Both menu slots bound, so the page has no empty-slot notices for axe to name (the shell's, not a card's).
        $about = addrPublished($this->editor, 'About', 'page');

        foreach (['primary', 'footer'] as $slot) {
            $menu = navCreate($this->editor, ucfirst($slot));
            navSave($this->editor, $menu, [['kind' => 'content', 'target_id' => $about->id]]);
            app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $menu->id, (string) $this->editor->id));
        }

        pageArticleFromDocument($this->editor, 'Harbour sounds', [
            avAudioNode($this->audio, ['caption' => 'The tide report', 'transcript' => "Good morning from the quay.\n\nHigh water is at 6:12."]),
            avVideoNode($video, ['posterMediaId' => $poster->id, 'caption' => 'Dawn over the harbour wall', 'videoWidth' => 1920, 'videoHeight' => 1080,
                'tracks' => [['mediaId' => $captions->id, 'language' => 'en', 'kind' => 'captions', 'label' => 'English', 'default' => true]]]),
        ]);
    };
});

it('passes axe on an article with an audio card and a video card in the light scheme, and opens the transcript (I-446)', function (): void {
    ($this->publishBoth)();

    visit('/harbour-sounds/')
        ->assertNoAccessibilityIssues(3)
        ->click('.card-audio__transcript summary')
        ->assertSee('High water is at 6:12.');
});

it('passes axe on the same article in the dark scheme (I-446)', function (): void {
    ($this->publishBoth)();
    designPublish(['theme.default' => ['color_scheme' => 'dark']]);

    visit('/harbour-sounds/')->assertNoAccessibilityIssues(3);
});

it('places an audio card from Insert, plays the chosen file in the editor, and saves its transcript (I-446)', function (): void {
    $draft = lifecycleDraft($this->editor, 'Tide report');
    // Warm the screen before the browser's first visit (I-638).
    $this->actingAs($this->editor)->get("/admin/content/{$draft->id}/edit")->assertOk();

    $page = cmSignIn($this->editor)
        ->navigate("/admin/content/{$draft->id}/edit")
        ->click('[role="toolbar"] button:has-text("Insert")')
        ->click('[role="menuitem"]:has-text("A sound file from the library")')
        ->assertNotPresent('[role="menu"]')
        ->assertScript($this->arrived, 0)
        ->click('[role="dialog"] [role="option"]:has-text("Tide report")')
        ->click('[role="dialog"] button:text-is("Insert audio")')
        ->assertNotPresent('[role="dialog"]')
        ->assertPresent('.node-audio audio')
        ->click('.node-audio button:text-is("No transcript")')
        ->type('.node-audio textarea', 'Good morning from the quay.')
        ->assertDontSee('Unsaved changes')
        ->assertDontSee('Saving…')
        ->assertSee('Saved');

    $card = collect(json_decode((string) DB::table('content_autosaves')->where('content_id', $draft->id)->value('document'), true)['content'] ?? [])->firstWhere('type', 'audio');

    expect($card['attrs'] ?? [])->toMatchArray(['mediaId' => $this->audio->id, 'transcript' => 'Good morning from the quay.']);
});
