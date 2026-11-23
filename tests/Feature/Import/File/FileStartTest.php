<?php

declare(strict_types=1);

use App\Domain\Import\Models\ImportRun;
use App\Http\Admin\Tools\Import\ImportRunController;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Import\FileImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    Queue::fake();
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->file = FileImports::ready('companion-site.xml', $this->admin);
    $this->show = '/admin/tools/import/'.$this->file->source_id;
    $this->choices = ['menu_locations' => [], 'templates' => [], 'owner' => (string) $this->admin->id, 'pause' => '1000',
        'authors' => array_fill_keys(['ada', 'grace', 'harbour', 'seo', 'reader'], 'new:author'),
        'media' => 'download', 'category_base' => 'category', 'tag_base' => 'tag', 'types' => [], 'drafts' => '1', 'comments' => '1'];
});

it('opens the site’s page at the Import step once the import starts, whatever page sent the form', function (): void {
    $this->actingAs($this->admin)->withSession(scConfirmed())->from('/admin/tools/import')
        ->post($this->show.'/start', $this->choices)
        ->assertRedirect(route('tools.importer.show', ['source' => $this->file->source_id]));

    $this->get($this->show)->assertInertia(fn (Assert $page) => $page->where('step', 'import'));
});

it('asks the plan to send a start again once, after the password’s confirmation interrupted it', function (): void {
    $this->actingAs($this->admin)->from($this->show)->post($this->show.'/start', $this->choices)->assertRedirect(route('password.confirm'));

    expect(ImportRun::query()->count())->toBe(0)
        ->and(session(ImportRunController::RESUME.$this->file->source_id))->toBeTrue();

    $plan = fn () => $this->withSession(scConfirmed())->get($this->show);

    $plan()->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('plan.resumeStart', true)));
    $plan()->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('plan.resumeStart', false)));
});

it('sends nothing again while the password isn’t confirmed', function (): void {
    $this->actingAs($this->admin)->from($this->show)->post($this->show.'/start', $this->choices);

    $this->get($this->show)->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('plan.resumeStart', false)));
});
