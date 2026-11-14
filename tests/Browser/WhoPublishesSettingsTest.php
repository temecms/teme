<?php

declare(strict_types=1);

use App\Domain\Seo\SearchAndSocial;
use App\Domain\StructuredData\WhoPublishes;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; the admin in a real browser needs its script.
    $this->withVite();
    $this->seed(RoleSeeder::class);

    // Each request starts afresh, as on a real server (CommentsScreenTest).
    Event::listen(RequestHandled::class, function (): void {
        app()->forgetScopedInstances();
        auth()->forgetGuards();
        app('session.store')->flush();
        app('cookie')->flushQueuedCookies();
    });

    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');

    // Warm the screen before the browser's first visit (I-638, B-356).
    $this->actingAs($this->admin)->get(route('settings.seo'))->assertOk();
});

it('passes axe on who publishes a restaurant, its week and its owner, every target 24 pixels or more, in the light and dark schemes', function (string $scheme): void {
    cmSettings([
        'publisher_type' => 'Restaurant', 'business_street_address' => '12 Aba Road', 'business_locality' => 'Port Harcourt',
        'business_hours' => [
            ['day' => 'monday', 'hours' => [['opens' => '09:00', 'closes' => '12:30'], ['opens' => '14:00', 'closes' => '18:00']], 'closed' => false],
            ['day' => 'sunday', 'hours' => [], 'closed' => true],
        ],
    ], SearchAndSocial::KEY);
    lscrPrefer($this->admin, ['appearance.mode' => $scheme]);

    $page = cmSignIn($this->admin)
        ->navigate('/admin/settings/seo')
        ->assertSee('The business')
        ->assertSee('Owned by')
        ->assertPresent('[data-test="hours-remove"]')
        ->assertNoAccessibilityIssues(3);

    $targets = cmSmallTargets($page);

    expect($targets['small'])->toBe([])
        ->and($targets['checked'])->toBeGreaterThan(0);
})->with(['light', 'dark']);

it('shows what each kind needs as it is chosen, keeps what a hidden field holds, and saves a restaurant\'s week (I-874, I-877)', function (): void {
    cmSettings(['publisher_owner_name' => 'Harbour Group', 'publisher_owner_url' => 'https://harbourgroup.example/'], SearchAndSocial::KEY);

    cmSignIn($this->admin)
        ->navigate('/admin/settings/seo')
        ->assertNotPresent('#field-publisher_name')
        ->select('#field-publisher_type', 'Restaurant')
        ->assertPresent('#field-publisher_name')
        ->assertPresent('#field-business_street_address')
        ->assertNotPresent('#field-publisher_person')
        ->type('#field-business_street_address', '12 Aba Road')
        ->type('#field-business_locality', 'Port Harcourt')
        ->type('#field-business_hours-monday-opens-0', '09:00')
        ->type('#field-business_hours-monday-closes-0', '17:00')
        ->click('#field-business_hours-sunday-closed')
        ->click('[data-test="settings-save"]')
        ->assertSee('Search and social settings saved.')
        ->select('#field-publisher_type', 'Person')
        ->assertPresent('#field-publisher_person')
        ->assertNotPresent('#field-business_street_address');

    $values = app(SettingsReader::class)->group(SearchAndSocial::KEY);

    expect(WhoPublishes::kind())->toBe('Restaurant')
        ->and($values['business_hours'])->toEqual([
            ['day' => 'monday', 'hours' => [['opens' => '09:00', 'closes' => '17:00']], 'closed' => false],
            ['day' => 'sunday', 'hours' => [], 'closed' => true],
        ])
        ->and($values['publisher_owner_name'])->toBe('Harbour Group');
});

it('fits a business\'s week on a phone, nothing scrolling sideways, and passes axe there', function (): void {
    cmSettings(['publisher_type' => 'Store', 'business_street_address' => '12 Aba Road', 'business_locality' => 'Port Harcourt'], SearchAndSocial::KEY);

    cmSignIn($this->admin)
        ->resize(375, 812)
        ->navigate('/admin/settings/seo')
        ->assertPresent('[data-test="hours-day"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoAccessibilityIssues(3);
});
