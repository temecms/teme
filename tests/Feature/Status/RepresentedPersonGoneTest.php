<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Status\Checks\RepresentedPersonGone;
use App\Domain\Status\StatusBanner;
use App\Domain\Users\Operations\DeleteUser;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Backups\HealthyBackups;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->withElevation();
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->jane = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    cmSettings(['publisher_type' => 'Person', 'publisher_person' => (string) $this->jane->id], SearchAndSocial::KEY);
    $this->delete = fn () => app(DeleteUser::class)->execute(new DeleteUserInput((string) $this->jane->id, null, (string) $this->admin->id));
});

it('warns whoever may change Search and social once the person the site represents has no account, linking to the section (I-878)', function (): void {
    ($this->delete)();

    $check = app(RepresentedPersonGone::class);

    expect($check->ability())->toBe('settings.seo.manage')
        ->and($check->banner())->toEqual(new StatusBanner(
            'warning',
            'The person the site represents no longer has an account, so search engines are told nobody in particular publishes it.',
            'Choose who publishes the site',
            route('settings.seo').'#section-who-publishes',
            40,
        ));
});

it('says nothing while the person has an account, or once the site represents anyone else (I-878)', function (string $case): void {
    if ($case !== 'a person with an account') {
        cmSettings(['publisher_type' => $case === 'an organization' ? 'Organization' : ''], SearchAndSocial::KEY);
        ($this->delete)();
    }

    expect(app(RepresentedPersonGone::class)->banner())->toBeNull();
})->with(['a person with an account', 'an organization', 'nobody in particular']);

it('shows the warning in the banner slot to administrators, and to no editor (I-221, I-878)', function (): void {
    HealthyBackups::seed();

    // The default theme draws two menus; each bound, so no other banner outranks this one.
    $nav = navCreate($this->admin);

    foreach (['primary', 'footer'] as $slot) {
        app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $nav->id, (string) $this->admin->id));
    }

    ($this->delete)();
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($this->admin)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('siteStatus.0.level', 'warning')
        ->where('siteStatus.0.actionLabel', 'Choose who publishes the site'));
    $this->actingAs($editor)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('siteStatus', []));
});
