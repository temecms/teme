<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A site as it stood before pages had their own: RoleSeeder's grants, less
    // page.*, with a page an author wrote while every writer could (I-482).
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->page = pgAuthorsPage($this->author, published: true);
    Permission::query()->where('name', 'like', 'page.%')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->migrate = function (): void {
        $path = database_path('migrations/2027_01_31_100000_add_page_permissions.php');

        expect($path)->toBeFile();
        (require $path)->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    };
});

it('brings pages’ six to administrators and editors alone, on a site from before them (I-3313)', function (): void {
    ($this->migrate)();

    foreach (RoleSeeder::ROLES as $role => $permissions) {
        expect(Role::findByName($role)->permissions->pluck('name')->all())->toEqualCanonicalizing($permissions);
    }

    $held = fn (string $role): array => Role::findByName($role)->permissions->pluck('name')->filter(fn (string $name): bool => str_starts_with($name, 'page.'))->sort()->values()->all();

    expect($held('administrator'))->toBe(['page.create', 'page.delete.any', 'page.delete.own', 'page.edit.any', 'page.edit.own', 'page.publish'])
        ->and($held('editor'))->toBe(['page.create', 'page.delete.any', 'page.edit.any', 'page.edit.own', 'page.publish'])
        ->and($held('author'))->toBe([])
        ->and($held('contributor'))->toBe([]);
});

it('leaves an author’s page theirs by name, and changes nothing about it but who may edit it', function (): void {
    $before = $this->page->refresh()->only(['author_id', 'status', 'title', 'working_revision_id', 'published_revision_id']);
    $editor = User::factory()->create()->assignRole('editor');

    ($this->migrate)();

    expect($this->page->refresh()->only(array_keys($before)))->toBe($before)
        ->and(Gate::forUser($this->author)->allows('update', $this->page))->toBeFalse()
        ->and(Gate::forUser($this->author)->allows('delete', $this->page))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $this->page))->toBeTrue()
        ->and(Gate::forUser($editor)->allows('publish', $this->page))->toBeTrue();
});

it('only adds: a grant made by hand stays, a missing role isn’t made, and a second run changes nothing', function (): void {
    Role::findByName('editor')->givePermissionTo('settings.general.manage');
    Role::findByName('contributor')->delete();

    ($this->migrate)();
    ($this->migrate)();

    expect(Role::findByName('editor')->hasPermissionTo('settings.general.manage'))->toBeTrue()
        ->and(Role::query()->where('name', 'contributor')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'like', 'page.%')->count())->toBe(6)
        ->and(Role::findByName('editor')->permissions->where('name', 'page.publish'))->toHaveCount(1);
});

it('lets an editor approve a page a contributor sent for review before the upgrade (ApproveReview)', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');
    $editor = User::factory()->create()->assignRole('editor');

    // Before the upgrade a contributor could write pages (I-482): the six are
    // made only so she can hold two, then removed again as on an old site.
    ($this->migrate)();
    Role::findByName('contributor')->givePermissionTo(['page.create', 'page.edit.own']);
    $page = lifecycleDraft($contributor, 'Opening hours', 'page');
    $review = schedSubmit($page, $contributor);
    Permission::query()->where('name', 'like', 'page.%')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ($this->migrate)();

    expect(Gate::forUser($contributor)->allows('update', $page->refresh()))->toBeFalse();

    schedApprove($review, $editor);

    expect($page->refresh()->status)->toBe('published')
        ->and($page->author_id)->toBe($contributor->id);
});

it('lists pages’ six in RoleSeeder for administrators and editors and nobody else', function (): void {
    ($this->migrate)();
    $this->seed(RoleSeeder::class);

    expect(RoleSeeder::PERMISSIONS)->toContain('page.create', 'page.edit.own', 'page.edit.any', 'page.publish', 'page.delete.own', 'page.delete.any')
        ->and(User::factory()->create()->assignRole('editor')->can('page.edit.any'))->toBeTrue()
        ->and(User::factory()->create()->assignRole('author')->can('page.create'))->toBeFalse()
        ->and(User::factory()->create()->assignRole('contributor')->can('page.create'))->toBeFalse();
});
