<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\People\Credit;
use App\Domain\People\Operations\Concerns\GivesAccountsPeople;
use App\Domain\People\PersonSlugs;
use App\Domain\People\Queries\People;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->people = app(People::class);
    $this->guest = function (string $name, string $slug, ?string $bio = null): string {
        $id = strtolower((string) Str::ulid());
        DB::table('people')->insert(['id' => $id, 'name' => $name, 'slug' => $slug, 'bio' => $bio, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('paths')->insert(['path' => 'author/'.$slug, 'target_type' => 'person', 'target_id' => $id, 'is_current' => true]);

        return $id;
    };
});

it('makes no person for a new account, through the factory too', function (): void {
    User::factory()->count(3)->create();

    expect(DB::table('people')->count())->toBe(0);
});

it('shows an account with its name, address and biography, with or without a person row', function (): void {
    $ada = User::factory()->create(['name' => 'Ada Lovelace', 'bio' => 'Wrote the first program.']);
    app(PathProjection::class)->syncUser($ada);
    $shown = $this->people->shown([Credit::account((int) $ada->id)]);

    expect($shown)->toHaveKey('user:'.$ada->id)
        ->and((array) $shown['user:'.$ada->id])->toBe(['kind' => 'user', 'id' => (string) $ada->id, 'name' => 'Ada Lovelace', 'slug' => $ada->slug, 'bio' => 'Wrote the first program.', 'path' => 'author/'.$ada->slug, 'profile' => null]);

    DB::table('people')->insert(['id' => strtolower((string) Str::ulid()), 'user_id' => $ada->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('users')->where('id', $ada->id)->update(['name' => 'Ada King', 'bio' => '  ']);

    expect((array) $this->people->shown([Credit::account((int) $ada->id)])['user:'.$ada->id])
        ->toBe(['kind' => 'user', 'id' => (string) $ada->id, 'name' => 'Ada King', 'slug' => $ada->slug, 'bio' => null, 'path' => 'author/'.$ada->slug, 'profile' => null]);
});

it('shows a guest with their own name, address and biography', function (): void {
    $reuters = ($this->guest)('Reuters', 'reuters', 'News agency');

    expect((array) $this->people->shown([Credit::person($reuters)])['person:'.$reuters])
        ->toBe(['kind' => 'person', 'id' => $reuters, 'name' => 'Reuters', 'slug' => 'reuters', 'bio' => 'News agency', 'path' => 'author/reuters', 'profile' => null]);
});

it('gives a guest a slug no account has, and an account a slug no guest has', function (): void {
    ($this->guest)('Ada', 'ada');
    $ada = User::factory()->create(['name' => 'Ada']);

    expect($ada->slug)->toBe('ada-2');

    User::factory()->create(['name' => 'Grace']);

    expect(PersonSlugs::unique('Grace'))->toBe('grace-2')
        ->and(PersonSlugs::unique('Hedy'))->toBe('hedy')
        ->and(PersonSlugs::unique('!!!'))->toBe('person')
        ->and(PersonSlugs::isFree('ada-2'))->toBeFalse()
        ->and(PersonSlugs::isFree('ada-3'))->toBeTrue();
});

it('finds an account by its slug and a guest by theirs', function (): void {
    $ada = User::factory()->create(['name' => 'Ada Lovelace']);
    $reuters = ($this->guest)('Reuters', 'reuters');

    expect($this->people->bySlug($ada->slug))->toEqual(Credit::account((int) $ada->id))
        ->and($this->people->bySlug('reuters'))->toEqual(Credit::person($reuters))
        ->and($this->people->bySlug('nobody'))->toBeNull();
});

it('shows any number of people of both kinds in one statement', function (): void {
    $credits = [];

    foreach (User::factory()->count(10)->create() as $user) {
        $credits[] = Credit::account((int) $user->id);
    }

    foreach (range(1, 10) as $n) {
        $credits[] = Credit::person(($this->guest)("Guest {$n}", "guest-{$n}"));
    }

    DB::enableQueryLog();
    $shown = $this->people->shown($credits);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($shown)->toHaveCount(20);
});

it('reads a credit key back as the credit it names, and refuses anything else', function (): void {
    $ulid = strtolower((string) Str::ulid());

    expect(Credit::fromKey('user:12'))->toEqual(Credit::account(12))
        ->and(Credit::fromKey('person:'.$ulid))->toEqual(Credit::person($ulid))
        ->and(Credit::account(12)->key())->toBe('user:12')
        ->and(Credit::person($ulid)->key())->toBe('person:'.$ulid)
        ->and(Credit::fromKey('user:0'))->toBeNull()
        ->and(Credit::fromKey('user:abc'))->toBeNull()
        ->and(Credit::fromKey("user:12\n"))->toBeNull()
        ->and(Credit::fromKey('person:'.strtoupper($ulid)))->toBeNull()
        ->and(Credit::fromKey('person:12'))->toBeNull()
        ->and(Credit::fromKey('other:12'))->toBeNull();
});

it('makes an account\'s person once, however often it is asked', function (): void {
    $ada = User::factory()->create();
    $operation = new class
    {
        use GivesAccountsPeople;

        /** @return array<int, string> */
        public function give(array $ids): array
        {
            return $this->personIdsFor($ids);
        }
    };

    $first = $operation->give([(int) $ada->id]);
    $second = $operation->give([(int) $ada->id, (int) $ada->id]);

    expect(DB::table('people')->count())->toBe(1)
        ->and($second)->toBe($first)
        ->and($first[(int) $ada->id])->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/');
});

it('makes the people of several accounts in account order, whatever order the credits name them', function (): void {
    [$a, $b, $c] = User::factory()->count(3)->create()->all();
    $operation = new class
    {
        use GivesAccountsPeople;

        /** @return array<int, string> */
        public function give(array $ids): array
        {
            return $this->personIdsFor($ids);
        }
    };

    DB::enableQueryLog();
    $operation->give([(int) $c->id, (int) $a->id, (int) $b->id]);
    $insert = collect(DB::getQueryLog())->first(fn (array $query): bool => str_starts_with($query['query'], 'INSERT INTO people'));

    expect([$insert['bindings'][1], $insert['bindings'][3], $insert['bindings'][5]])->toBe([(int) $a->id, (int) $b->id, (int) $c->id]);
});
