<?php

declare(strict_types=1);

use App\Domain\Settings\Queries\FindSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('draws the content group as Settings › Writing, at its own address', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/writing')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/group')
            ->where('heading', ['key' => 'writing', 'label' => 'Writing', 'description' => 'How long the trash keeps items and comments.'])
            ->where('section', null)
            ->where('group.key', 'content')
            ->where('fields.0.key', 'trash_days')
            ->where('values.trash_days', 30));
});

it('draws the security group as Settings › People, its form under Sign-in rules', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/people')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/group')
            ->where('heading', ['key' => 'people', 'label' => 'People', 'description' => 'How everyone with an account signs in.'])
            ->where('section', 'Sign-in rules')
            ->where('group.key', 'security')
            ->where('group.description', 'Password rules, sign-in limits and session lengths. Changing any of these asks you to confirm your password.')
            ->where('fields.0.key', 'password_min_length'));
});

it('heads every other page with its own group\'s name and line', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/media')
        ->assertInertia(fn (Assert $page) => $page
            ->where('heading.key', 'media')
            ->where('heading.label', 'Media')
            ->where('section', null)
            ->where('group.key', 'media'));
});

it('sends the old addresses to the new pages', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/content')->assertRedirect('/admin/settings/writing');
    $this->actingAs($this->admin)->get('/admin/settings/security')->assertRedirect('/admin/settings/people');
});

it('saves Writing through the content group, and returns to Writing', function (): void {
    $this->actingAs($this->admin)->from('/admin/settings/writing')
        ->put('/admin/settings/content', ['values' => ['trash_days' => 14], 'expected_version' => 0])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/writing');

    expect(app(SettingsReader::class)->int('content', 'trash_days'))->toBe(14);
});

it('opens each page for whoever may change its own group', function (): void {
    $person = User::factory()->create();
    $person->givePermissionTo('settings.content.manage');

    $this->actingAs($person)->get('/admin/settings/writing')->assertOk();
    $this->actingAs($person)->get('/admin/settings/people')->assertForbidden();
});

it('finds each setting on the page that draws it, and Writing by its own line', function (): void {
    $found = app(FindSettings::class)->run('trash', $this->admin);

    expect($found['settings'])->toContain(['key' => 'content.trash_days', 'label' => 'Keep trash for', 'screen' => 'Writing', 'href' => '/admin/settings/writing#field-trash_days'])
        ->and(array_column($found['screens'], 'key'))->toContain('writing')
        ->and(app(FindSettings::class)->run('minimum password', $this->admin)['settings'])
        ->toContain(['key' => 'security.password_min_length', 'label' => 'Minimum password length', 'screen' => 'People', 'href' => '/admin/settings/people#field-password_min_length']);
});

it('says on Media which image widths Teme makes, in words read from what it makes', function (): void {
    $this->actingAs($this->admin)->get('/admin/settings/media')
        ->assertInertia(fn (Assert $page) => $page
            ->where('heading.description', 'Limits on the files people can upload, and the image widths Teme makes.')
            ->where('imageWidths', [
                'label' => 'Image widths',
                'value' => '320, 640, 960, 1280, 1920 and 2560 pixels wide, and a 300 × 300 thumbnail.',
                'hint' => 'Fixed in Teme, not a setting. A width is made only when the original is wider, plus one at the original’s own width, so nothing is enlarged. All are saved as WebP. An image used as a featured or social image also gets a 1200 × 630 social card and the crops search engines may show.',
            ]));

    // Only Media draws them, and Find a setting finds Media by them.
    $this->actingAs($this->admin)->get('/admin/settings/general')->assertInertia(fn (Assert $page) => $page->where('imageWidths', null));

    expect(array_column(app(FindSettings::class)->run('image widths', $this->admin)['screens'], 'key'))->toContain('media');
});
