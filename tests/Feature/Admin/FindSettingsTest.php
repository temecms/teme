<?php

declare(strict_types=1);

use App\Domain\Settings\Queries\FindSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('finds a setting by the words of its name, in any order and case, on its page, with an address that lands on its field', function (): void {
    $this->actingAs($this->admin)->getJson('/admin/search/settings?q='.urlencode('ZONE time'))
        ->assertOk()
        ->assertJsonPath('settings.0', [
            'key' => 'general.timezone',
            'label' => 'Time zone',
            'screen' => 'General',
            'href' => route('settings.show', ['group' => 'general'], false).'#field-timezone',
        ]);
});

it('finds a setting by its description, and a Settings page by its name or description', function (): void {
    $found = $this->actingAs($this->admin)->getJson('/admin/search/settings?q=megapixels')->assertOk()->json();

    expect(array_column($found['settings'], 'key'))->toContain('media.max_megapixels')
        ->and($found['screens'])->toBe([]);

    $this->actingAs($this->admin)->getJson('/admin/search/settings?q=tagline')
        ->assertOk()
        ->assertJsonPath('screens.0', ['key' => 'general', 'label' => 'General', 'href' => route('settings.show', ['group' => 'general'], false)])
        ->assertJsonPath('settings.0.key', 'general.tagline');
});

it('needs every word it is given', function (): void {
    $this->actingAs($this->admin)->getJson('/admin/search/settings?q='.urlencode('time banana'))
        ->assertOk()
        ->assertExactJson(['screens' => [], 'settings' => []]);
});

it('finds nothing in a group the person may not change, and finds a group\'s setting on the screen it is changed on', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->getJson('/admin/search/settings?q=time')
        ->assertOk()
        ->assertExactJson(['screens' => [], 'settings' => []]);

    // The comments switch is the modules group's, changed on Discussion's screen.
    $this->actingAs($this->admin)->getJson('/admin/search/settings?q='.urlencode('comments leaves the menu'))
        ->assertOk()
        ->assertJsonFragment(['key' => 'modules.comments', 'label' => 'Comments', 'screen' => 'Discussion', 'href' => route('settings.show', ['group' => 'discussion'], false).'#field-comments']);

    // Discussion's screen is theirs, the modules group isn't.
    $moderator = User::factory()->create();
    $moderator->givePermissionTo('settings.discussion.manage');

    expect(array_column($this->actingAs($moderator)->getJson('/admin/search/settings?q='.urlencode('comments leaves the menu'))->assertOk()->json('settings'), 'key'))->not->toContain('modules.comments')
        ->and($this->actingAs($moderator)->getJson('/admin/search/settings?q=comments')->assertOk()->json('settings'))->not->toBeEmpty();
});

it('stops at the limit, in the Settings menu\'s order', function (): void {
    $found = app(FindSettings::class)->run('the', $this->admin, 3)['settings'];

    expect($found)->toHaveCount(3)
        ->and($found[0]['screen'])->toBe('General');
});

it('answers a blank query with nothing, and a guest with no answer', function (): void {
    $this->getJson('/admin/search/settings?q=time')->assertUnauthorized();

    $this->actingAs($this->admin)->getJson('/admin/search/settings?q='.urlencode('   '))
        ->assertOk()
        ->assertExactJson(['screens' => [], 'settings' => []]);
});

it('shares the palette\'s limit of 60 searches a minute per person', function (): void {
    foreach (range(1, 30) as $n) {
        $this->actingAs($this->admin)->getJson('/admin/search?q=')->assertOk();
        $this->actingAs($this->admin)->getJson('/admin/search/settings?q=')->assertOk();
    }

    $this->actingAs($this->admin)->getJson('/admin/search/settings?q=')->assertTooManyRequests();
});
