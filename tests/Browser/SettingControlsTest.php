<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsGroup;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // A group of this test's own, on the generic settings form.
    app(SettingsRegistry::class)->group(new SettingsGroup(
        key: 'test_controls',
        label: 'Test controls',
        description: 'A group for this test.',
        section: 'test_controls',
        ability: 'settings.general.manage',
        settings: [
            Setting::media('picture', 'Picture', 'An image for this test.', '0.1.0'),
            Setting::pairs('tags', 'Tags', 'Pairs for this test.', '0.1.0', 'Name', 'Content'),
        ],
        since: '0.1.0',
    ));
    $this->read = fn (string $key): mixed => app(SettingsReader::class)->get('test_controls', $key);

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get('/admin/settings/test_controls')->assertOk();
});

it('adds rows of pairs, saves them, and saves an empty list once every row is removed', function (): void {
    $page = cmSignIn($this->admin)
        ->navigate('/admin/settings/test_controls')
        ->assertSee('Nothing yet.')
        ->click('[data-test="pairs-add"]')
        ->type('[name="values[tags][0][name]"]', 'google-site-verification')
        ->type('[name="values[tags][0][value]"]', 'abc')
        ->click('[data-test="pairs-add"]')
        ->type('[name="values[tags][1][name]"]', 'msvalidate.01')
        ->type('[name="values[tags][1][value]"]', 'DEF')
        ->click('[data-test="settings-save"]')
        ->assertSee('Settings saved.');

    expect(($this->read)('tags'))->toEqual([['name' => 'google-site-verification', 'value' => 'abc'], ['name' => 'msvalidate.01', 'value' => 'DEF']]);

    $page->navigate('/admin/settings/test_controls')
        ->assertValue('[name="values[tags][1][name]"]', 'msvalidate.01')
        ->click('[data-test="pairs-row"]:first-of-type [data-test="pairs-remove"]')
        ->click('[data-test="pairs-row"]:first-of-type [data-test="pairs-remove"]')
        ->assertSee('Nothing yet.')
        ->click('[data-test="settings-save"]')
        ->assertSee('Settings saved.');

    expect(($this->read)('tags'))->toBe([]);
});

it('chooses an image from the library, shows it, and removes it (I-418)', function (): void {
    $picture = pageImage($this->admin, 1200, 630);

    $page = cmSignIn($this->admin)
        ->navigate('/admin/settings/test_controls')
        ->click('[data-test="media-choose"]')
        ->assertPresent('[role="listbox"]')
        // Wait for the dialog to settle before clicking inside it (issue #60).
        ->assertScript("Array.from(document.querySelectorAll('[data-slot=\"dialog-content\"], [data-slot=\"dialog-overlay\"]')).every((element) => element.getAnimations().length === 0)")
        ->click('[role="option"]')
        ->click('[data-test="media-modal-use"]')
        ->assertPresent('[data-test="media-preview"]')
        ->click('[data-test="settings-save"]')
        ->assertSee('Settings saved.');

    expect(($this->read)('picture'))->toBe($picture->id);

    $page->navigate('/admin/settings/test_controls')
        ->assertPresent('[data-test="media-preview"]')
        ->click('[data-test="media-remove"]')
        ->assertPresent('[data-test="media-choose"]')
        ->click('[data-test="settings-save"]')
        ->assertSee('Settings saved.');

    expect(($this->read)('picture'))->toBe('');
});
