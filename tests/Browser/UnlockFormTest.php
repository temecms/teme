<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    Visibilities::change(Visibilities::published($this->editor, 'Members update', 'zqprotected'), 'protected', $this->editor, 'Harbour 2024');

    // An empty menu draws no landmark, where an unbound slot draws a note (twice, outside production) that axe counts.
    foreach (['primary', 'footer'] as $slot) {
        app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, navCreate($this->editor, ucfirst($slot))->id, (string) $this->editor->id));
    }
});

it('asks for the password, says when it is wrong, opens the page with the right one, and passes axe in light and dark (I-1700)', function (string $scheme): void {
    designPublish(['theme.default' => ['color_scheme' => $scheme]]);

    $page = visit('/members-update/')
        ->assertSee('This post is protected by a password. Enter it to read on.')
        ->assertNoAccessibilityIssues(3)
        ->type('#teme-password', 'harbour 2024')
        ->press('Unlock');
    cmWaitUntil($page, "document.querySelector('#teme-password')?.getAttribute('aria-invalid') === 'true'");
    $page->assertSee('That isn’t the password. Check it and try again.')
        ->assertNoAccessibilityIssues(3)
        ->type('#teme-password', 'Harbour 2024')
        ->press('Unlock');
    cmWaitUntil($page, "document.body.textContent.includes('zqprotectedbody')");
    $page->assertDontSee('This post is protected by a password.')
        ->assertNoAccessibilityIssues(3);
})->with(['light', 'dark']);
