<?php

declare(strict_types=1);

use App\Domain\Addresses\Paths;
use App\Domain\People\Operations\CreatePerson;
use App\Domain\People\Operations\Input\CreatePersonInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->add = fn (string $name, ?string $slug = null, ?string $bio = null, ?User $actor = null): string => app(CreatePerson::class)->execute(new CreatePersonInput((string) ($actor ?? $this->admin)->id, $name, $slug, $bio));
    $this->refusal = function (Closure $run): array {
        try {
            $run();
        } catch (ValidationException $refused) {
            return $refused->errors();
        }

        return [];
    };
});

it('adds a person with no account, at their own address', function (): void {
    $id = ($this->add)('Reuters', 'reuters', '  News agency  ');

    expect((array) DB::table('people')->where('id', $id)->first(['user_id', 'name', 'slug', 'bio']))->toBe(['user_id' => null, 'name' => 'Reuters', 'slug' => 'reuters', 'bio' => 'News agency'])
        ->and($id)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and(Paths::current('person', $id))->toBe('author/reuters');

    addrGet('/author/reuters/')->assertOk()->assertSee('Reuters');
});

it('makes a slug from the name when none is given', function (): void {
    User::factory()->create(['name' => 'Grace Hopper']);

    expect(DB::table('people')->where('id', ($this->add)('Grace Hopper'))->value('slug'))->toBe('grace-hopper-2')
        ->and(DB::table('people')->where('id', ($this->add)('???'))->value('slug'))->toBe('person');
});

it('refuses a slug an account has, or an address another target holds', function (): void {
    $grace = User::factory()->create(['name' => 'Grace Hopper']);
    DB::table('paths')->insert(['path' => 'author/taken', 'target_type' => 'content', 'target_id' => '01kv7m3q9x2c5r8t1w4y6z0b3d', 'is_current' => true]);

    expect(($this->refusal)(fn () => ($this->add)('Grace', $grace->slug)))->toBe(['slug' => ['Someone already has the address /author/'.$grace->slug.'/.']])
        ->and(($this->refusal)(fn () => ($this->add)('Taken', 'taken'))['slug'][0] ?? null)->toStartWith('The address /author/taken/ is used by')
        ->and(($this->refusal)(fn () => ($this->add)('Bad', 'Not A Slug')))->toBe(['slug' => ['Use lower-case letters, numbers and hyphens.']])
        ->and(($this->refusal)(fn () => ($this->add)('   ')))->toBe(['name' => ['Give the person a name.']])
        ->and(DB::table('people')->count())->toBe(0);
});

it('says the address an account\'s slug holds under the author base the site uses', function (): void {
    addrChange($this->admin, ['authorBase' => 'writers']);
    $grace = User::factory()->create(['name' => 'Grace Hopper']);

    expect(($this->refusal)(fn () => ($this->add)('Grace', $grace->slug)))->toBe(['slug' => ['Someone already has the address /writers/'.$grace->slug.'/.']])
        ->and(DB::table('people')->count())->toBe(0);
});

it('writes nothing when someone without users.manage asks', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $paths = DB::table('paths')->count();

    expect(fn () => ($this->add)('Reuters', 'reuters', null, $editor))->toThrow(AuthorizationException::class);

    expect(DB::table('people')->count())->toBe(0)
        ->and(DB::table('paths')->count())->toBe($paths);
});

it('lets the operator add one', function (): void {
    $this->withElevation();

    $id = app(CreatePerson::class)->execute(new CreatePersonInput(null, 'Reuters', 'reuters'));

    expect(Paths::current('person', $id))->toBe('author/reuters');
});
