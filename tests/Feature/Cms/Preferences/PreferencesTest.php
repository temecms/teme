<?php

declare(strict_types=1);

use App\Domain\Preferences\Operations\Input\UpdatePreferencesInput;
use App\Domain\Preferences\Operations\UpdatePreferences;
use App\Domain\Preferences\PreferenceDefinition;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Preferences\Queries\PreferencesFor;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('gives a person the declared defaults before they change anything', function (): void {
    expect(PreferencesFor::user($this->author))->toBe([
        'editor.spellcheck' => true,
        'editor.smart_quotes' => true,
        'editor.rail_width' => 360,
        'editor.focus_mode_default' => false,
        'lists.per_page' => 50,
        'appearance.mode' => 'system',
        'appearance.look' => 'dark_chrome',
        'appearance.high_contrast' => false,
        'dashboard.getting_started_hidden' => false,
        'notifications.comments.waiting' => ['mail'],
        'notifications.comments.approved' => ['mail'],
        'lists.post.columns' => ['author', 'taxonomy-category', 'taxonomy-tag', 'comments', 'date'],
        'lists.page.columns' => ['author', 'comments', 'date'],
        'nav.hidden' => [],
        'nav.order' => [],
        'nav.pinned' => [],
    ])->and($this->author->refresh()->preferences)->toBe([]);
});

it('refuses a key that is both a leaf and a prefix of another', function (): void {
    $modes = ['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'];
    $registry = new PreferenceRegistry;
    $registry->register(new PreferenceDefinition('appearance.mode', 'select', 'system', label: 'Mode', options: $modes));

    expect(fn () => $registry->register(new PreferenceDefinition('appearance', 'select', 'system', label: 'Mode', options: $modes)))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps a list in the order given, and refuses a value the callback does not allow', function (): void {
    palListPreference();

    app(UpdatePreferences::class)->execute(new UpdatePreferencesInput(['pal.order' => ['media', 'posts', 'pages']], (string) $this->author->id));

    expect(PreferencesFor::user($this->author->refresh())['pal.order'])->toBe(['media', 'posts', 'pages']);

    expect(fn () => app(UpdatePreferences::class)->execute(new UpdatePreferencesInput(['pal.order' => ['posts', 'plugins']], (string) $this->author->id)))
        ->toThrow(ValidationException::class);

    expect(PreferencesFor::user($this->author->refresh())['pal.order'])->toBe(['media', 'posts', 'pages']);
});

it('keeps one person\'s preference off another person\'s screen', function (): void {
    app(UpdatePreferences::class)->execute(new UpdatePreferencesInput(['editor.spellcheck' => false], (string) $this->author->id));

    expect(PreferencesFor::user($this->editor)['editor.spellcheck'])->toBeTrue();
});

it('refuses a preference nothing declares, and writes nothing', function (): void {
    expect(fn () => app(UpdatePreferences::class)->execute(new UpdatePreferencesInput(['nobody.declared' => true, 'editor.spellcheck' => false], (string) $this->author->id)))
        ->toThrow(ValidationException::class);

    expect($this->author->refresh()->preferences)->toBe([]);
});

it('refuses a value the declaration does not allow, and writes nothing', function (string $key, mixed $value): void {
    expect(fn () => app(UpdatePreferences::class)->execute(new UpdatePreferencesInput([$key => $value], (string) $this->author->id)))
        ->toThrow(ValidationException::class);

    expect($this->author->refresh()->preferences)->toBe([]);
})->with([
    'a boolean that is not one' => ['editor.spellcheck', 'maybe'],
    'a number above its maximum' => ['editor.rail_width', 900],
    'a number below its minimum' => ['editor.rail_width', '100'],
    'a select value not offered' => ['lists.per_page', 30],
    'a select label, not its value' => ['appearance.mode', 'Dark'],
]);

it('writes nothing when authorization fails', function (): void {
    // The input carries only the actor, so there is no way to name someone
    // else's row; what can fail is the actor. A suspended person is refused.
    $suspended = User::factory()->create(['suspended_at' => Date::now()])->assignRole('author');

    expect(fn () => app(UpdatePreferences::class)->execute(new UpdatePreferencesInput(['editor.spellcheck' => false], (string) $suspended->id)))
        ->toThrow(AuthorizationException::class);

    expect($suspended->refresh()->preferences)->toBe([])
        ->and(DB::table('users')->whereRaw("preferences <> '{}'::jsonb")->count())->toBe(0);
});

it('keeps the value through a round trip of every declared type', function (): void {
    palListPreference();

    app(UpdatePreferences::class)->execute(new UpdatePreferencesInput([
        'editor.spellcheck' => '0',         // boolean, as a form sends it
        'editor.rail_width' => '400',       // number, as a form sends it
        'lists.per_page' => '100',          // select whose values are numbers
        'appearance.mode' => 'dark',        // select whose values are strings
        'pal.order' => ['pages', 'posts'],  // list
    ], (string) $this->author->id));

    $preferences = PreferencesFor::user($this->author->refresh());

    expect($preferences['editor.spellcheck'])->toBeFalse()
        ->and($preferences['editor.rail_width'])->toBe(400)
        ->and($preferences['lists.per_page'])->toBe(100)
        ->and($preferences['appearance.mode'])->toBe('dark')
        ->and($preferences['pal.order'])->toBe(['pages', 'posts'])
        ->and(DB::table('users')->where('id', $this->author->id)->value(DB::raw("jsonb_typeof(preferences->'editor.spellcheck')")))->toBe('boolean')
        ->and(DB::table('users')->where('id', $this->author->id)->value(DB::raw("jsonb_typeof(preferences->'lists.per_page')")))->toBe('number');
});
