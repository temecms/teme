<?php

declare(strict_types=1);

use App\Domain\Ai\AiSubjectType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->item = aiDraft($this->author, 'The ferry is late.');
    aiReady(fallback: 'fake:fake-unpriced');
    // An image's task, which the editor's assistant never offers.
    aiReady('fixture-alt', AiSubjectType::Media);
});

it('offers the editor\'s assistant the item tasks a person may ask for, and where their text goes (I-321, I-1304)', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($this->author)->get(route('content.edit', $this->item->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ai.tasks', [['key' => 'fixture', 'label' => 'Fixture']])
            ->where('ai.sentTo', ['Fake AI'])
            ->where('ai.canManage', false)
            ->where('ai.hourlyLimit', 60));

    $this->actingAs($admin)->get(route('content.edit', $this->item->id))
        ->assertInertia(fn (Assert $page) => $page->where('ai.canManage', true));
});

it('offers nothing to someone without ai.use, while AI is off, or while no item task has a model', function (string $case): void {
    $person = $this->author;

    if ($case === 'a contributor') {
        $person = User::factory()->create()->assignRole('contributor');
        $this->item = aiDraft($person, 'The ferry is late.');
    } elseif ($case === 'AI switched off') {
        config(['teme.ai.enabled' => false]);
    } else {
        aiChoose('fixture', '');
    }

    $this->actingAs($person)->get(route('content.edit', $this->item->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('ai', null));
})->with(['a contributor', 'AI switched off', 'no model for the item task']);
