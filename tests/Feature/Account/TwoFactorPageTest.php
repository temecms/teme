<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class TwoFactorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_factor_page_is_displayed(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('two-factor.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('account/two-factor')
                ->where('canManageTwoFactor', true)
                ->where('twoFactorEnabled', false)
                ->where('requiresConfirmation', true),
            );
    }

    public function test_two_factor_page_renders_without_two_factor_when_feature_is_disabled(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        config(['fortify.features' => []]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('two-factor.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('account/two-factor')
                ->where('canManageTwoFactor', false)
                ->missing('twoFactorEnabled')
                ->missing('requiresConfirmation'),
            );
    }

    public function test_two_factor_page_asks_for_the_password_again_before_showing_it(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->create();

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $this->actingAs($user)
            ->get(route('two-factor.edit'))
            ->assertRedirect(route('password.confirm'));
    }
}
