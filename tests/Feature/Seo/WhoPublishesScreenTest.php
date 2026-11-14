<?php

declare(strict_types=1);

use App\Domain\Seo\Queries\SearchAndSocialScreen;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Users\Operations\DeleteUser;
use App\Domain\Users\Operations\Input\DeleteUserInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
});

it('gives the screen the kinds in their groups, the site\'s title for an empty name, and each field\'s rule for when it shows (I-874, I-876)', function (): void {
    $data = app(SearchAndSocialScreen::class)->data($this->admin);
    $kinds = collect($data['publisher']['kinds']);
    $fields = collect(collect($data['sections'])->firstWhere('key', 'who-publishes')['fields'])->keyBy('key');

    expect($kinds->first())->toBe(['value' => '', 'label' => 'Nobody in particular', 'group' => null])
        ->and($kinds->firstWhere('value', 'NewsMediaOrganization'))->toBe(['value' => 'NewsMediaOrganization', 'label' => 'News media organization', 'group' => 'An organization'])
        ->and($kinds->firstWhere('value', 'Restaurant'))->toBe(['value' => 'Restaurant', 'label' => 'Restaurant', 'group' => 'A local business'])
        ->and($kinds->last())->toBe(['value' => 'Person', 'label' => 'A person', 'group' => null])
        ->and($data['publisher']['siteTitle'])->toBe(pageSiteName())
        ->and($data['publisher']['personGone'])->toBeFalse()
        ->and($fields['publisher_type']['visibleWhen'])->toBe([])
        ->and($fields['publisher_person']['visibleWhen'])->toBe(['publisher_type' => 'Person'])
        ->and($fields['publisher_person']['people'])->toContain(['value' => (string) $this->admin->id, 'label' => 'Ada Admin']);
});

it('tells the screen when the person the site represents no longer has an account (I-878)', function (): void {
    $this->withElevation();
    $jane = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    cmSettings(['publisher_type' => 'Person', 'publisher_person' => (string) $jane->id], SearchAndSocial::KEY);
    app(DeleteUser::class)->execute(new DeleteUserInput((string) $jane->id, null, (string) $this->admin->id));

    $this->actingAs($this->admin)->get('/admin/settings/seo')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/search-and-social')
            ->where('publisher.personGone', true)
            ->where('values.publisher_person', (string) $jane->id));
});
