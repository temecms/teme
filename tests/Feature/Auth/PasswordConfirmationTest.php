<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('password.confirm'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/confirm-password'),
        );
    }

    public function test_confirm_password_names_the_page_it_guards_and_where_cancel_leads(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['url.intended' => route('security.edit')])
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/confirm-password')
                ->where('guards', 'Security')
                ->where('cancelUrl', route('dashboard')),
            );
    }

    public function test_confirm_password_names_no_page_for_one_it_does_not_know(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['url.intended' => url('/admin/no-such-page')])
            ->get(route('password.confirm'))
            ->assertInertia(fn (Assert $page) => $page->where('guards', null));
    }

    public function test_password_confirmation_requires_authentication(): void
    {
        $response = $this->get(route('password.confirm'));

        $response->assertRedirect(route('login'));
    }
}
