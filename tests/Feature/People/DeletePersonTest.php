<?php

declare(strict_types=1);

use App\Domain\People\Credit;
use App\Domain\People\Operations\DeletePerson;
use App\Domain\People\Operations\Input\DeletePersonInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->reuters = Credited::guest('Reuters', 'reuters', 'News agency');
    $this->delete = fn (?User $actor = null, ?string $id = null): mixed => app(DeletePerson::class)->execute(new DeletePersonInput((string) ($actor ?? $this->admin)->id, $id ?? $this->reuters));
});

it('deletes a guest nothing credits, and their page', function (): void {
    ($this->delete)();

    expect(DB::table('people')->count())->toBe(0)
        ->and(DB::table('paths')->where('target_type', 'person')->count())->toBe(0);

    addrGet('/author/reuters/')->assertNotFound();
});

it('refuses while an item credits them, counting the items', function (): void {
    $first = lifecycleDraft($this->admin, 'First');
    Credited::set($first->id, [Credit::person($this->reuters)]);

    expect(fn () => ($this->delete)())->toThrow(ValidationException::class, 'Reuters is credited on 1 item. Take them off it first.');

    $second = lifecycleDraft($this->admin, 'Second');
    Credited::set($second->id, [Credit::person($this->reuters)]);

    expect(fn () => ($this->delete)())->toThrow(ValidationException::class, 'Reuters is credited on 2 items. Take them off them first.')
        ->and(DB::table('people')->count())->toBe(1);
});

it('refuses an account\'s person', function (): void {
    $ada = User::factory()->create(['name' => 'Ada Lovelace']);
    $article = lifecycleDraft($this->admin, 'Article');
    Credited::set($article->id, [Credit::account((int) $ada->id)]);
    Credited::set($article->id, []);
    $person = (string) DB::table('people')->where('user_id', $ada->id)->value('id');

    expect(fn () => ($this->delete)(id: $person))->toThrow(ValidationException::class, "Their name, address and biography come from their account's profile.");
});

it('writes nothing when refused', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $before = [DB::table('people')->get()->all(), DB::table('paths')->get()->all()];

    expect(fn () => ($this->delete)($editor))->toThrow(AuthorizationException::class);

    expect([DB::table('people')->get()->all(), DB::table('paths')->get()->all()])->toEqual($before);
});
