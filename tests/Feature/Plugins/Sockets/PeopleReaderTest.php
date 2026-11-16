<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\PluginWords;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\People\PeopleReader;
use Teme\Contracts\People\PersonPage;
use Teme\Contracts\People\PersonRef;
use Teme\Contracts\Plugins\Capability;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->doors = fn (array $capabilities = ['people.read']): array => app(PluginDoors::class)
        ->for('tests/scheduler', 'scheduler', $capabilities, []);
});

/**
 * Every person the reader names, page after page.
 *
 * @return array{people: list<PersonRef>, pages: int}
 */
function prAll(PeopleReader $reader, int $limit): array
{
    $people = [];
    $pages = 0;
    $after = null;

    do {
        $page = $reader->writers($after, $limit);
        $people = [...$people, ...$page->people];
        $after = $page->next;
        $pages++;
    } while ($after !== null && $pages < 20);

    return ['people' => $people, 'pages' => $pages];
}

it('names the active people who may write, by ID and display name, in pages whose cursors chain to the end', function (): void {
    $editor = User::factory()->create(['name' => 'Eddie Editor'])->assignRole('editor');
    $author = User::factory()->create(['name' => 'Ada Author'])->assignRole('author');
    $contributor = User::factory()->create(['name' => 'Cy Contributor'])->assignRole('contributor');
    User::factory()->create(['name' => 'No Role']);
    User::factory()->create(['name' => 'Suspended Sam', 'suspended_at' => now()])->assignRole('author');

    $all = prAll(($this->doors)()[PeopleReader::class], 2);

    expect($all['pages'])->toBe(2)
        ->and(array_map(fn (PersonRef $person): array => [$person->id, $person->name], $all['people']))->toBe([
            [(string) $editor->id, 'Eddie Editor'],
            [(string) $author->id, 'Ada Author'],
            [(string) $contributor->id, 'Cy Contributor'],
        ]);
});

it('gives nothing about a person but their ID and name', function (): void {
    User::factory()->create(['name' => 'Eddie Editor', 'email' => 'eddie@example.com'])->assignRole('editor');

    $page = ($this->doors)()[PeopleReader::class]->writers();

    expect(array_map(fn (ReflectionProperty $property): string => $property->getName(), (new ReflectionClass(PersonRef::class))->getProperties()))->toBe(['id', 'name'])
        ->and(json_encode($page))->not->toContain('eddie@example.com')
        ->and($page)->toBeInstanceOf(PersonPage::class);
});

it('refuses a cursor it did not give, and a limit outside its bounds', function (?string $after, int $limit): void {
    expect(fn () => ($this->doors)()[PeopleReader::class]->writers($after, $limit))->toThrow(InvalidArgumentException::class);
})->with([
    'a cursor it didn’t give' => ['bm90LWEtY3Vyc29y', 10],
    'a limit of 0' => [null, 0],
    'a limit of 201' => [null, 201],
]);

it('is offered only with people.read, which the administrator is asked for in plain words', function (): void {
    expect(($this->doors)([]))->not->toHaveKey(PeopleReader::class)
        ->and(($this->doors)())->toHaveKey(PeopleReader::class)
        ->and(Capability::tryFrom('people.read'))->toBe(Capability::PeopleRead)
        ->and(PluginWords::capability('people.read'))->toBe('See the names of the people who write on this site');
});
