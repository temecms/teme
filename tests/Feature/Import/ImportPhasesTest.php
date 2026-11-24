<?php

declare(strict_types=1);

use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportPhase;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ImportStopped;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Phases\StepPhase;
use App\Domain\Import\Phases\StepResult;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Source\SourceReader;
use Tests\Fixtures\Import\NotesStep;
use Tests\Fixtures\Import\PeoplePhase;

/**
 * A step with this key and order that does nothing: a plugin's, or a wrong one.
 */
function phasesStep(string $key, int $order): StepPhase
{
    return new class($key, $order) implements StepPhase
    {
        public function __construct(private readonly string $key, private readonly int $order) {}

        public function key(): string
        {
            return $this->key;
        }

        public function order(): int
        {
            return $this->order;
        }

        public function label(): string
        {
            return 'Things';
        }

        public function runsIn(ImportRunKind $kind): bool
        {
            return true;
        }

        public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
        {
            return StepResult::done();
        }
    };
}

/**
 * Every phase key a run of this kind visits, in order, as the engine walks them.
 *
 * @return list<string>
 */
function phasesChain(ImportRunKind $kind): array
{
    $phases = app(ImportPhases::class);
    $keys = [];

    for ($phase = $phases->first($kind); $phase !== null; $phase = $phases->after($phase->key(), $kind)) {
        $keys[] = $phase->key();
    }

    return $keys;
}

it('lists its phases by order, each found by its key among the others', function (): void {
    importPhase(new NotesStep, new PeoplePhase, phasesStep('plugin-things', 555));

    $keys = array_map(fn (ImportPhase $phase): string => $phase->key(), app(ImportPhases::class)->all());

    expect(array_values(array_intersect($keys, ['fixture-people', 'plugin-things', 'fixture-notes'])))->toBe(['fixture-people', 'plugin-things', 'fixture-notes'])
        ->and(app(ImportPhases::class)->find('fixture-notes'))->toBeInstanceOf(NotesStep::class)
        ->and(app(ImportPhases::class)->find('fixture-missing'))->toBeNull();
});

it('walks a run\'s phases in order, skipping one its kind doesn\'t do, and starts again at the first for a key it no longer has', function (): void {
    importPhase(new NotesStep, new PeoplePhase);
    $phases = app(ImportPhases::class);

    expect(array_values(array_intersect(phasesChain(ImportRunKind::Full), ['fixture-people', 'fixture-notes'])))->toBe(['fixture-people', 'fixture-notes'])
        ->and(array_values(array_intersect(phasesChain(ImportRunKind::CatchUp), ['fixture-people', 'fixture-notes'])))->toBe(['fixture-people'])
        ->and($phases->after('fixture-gone', ImportRunKind::Full)?->key())->toBe($phases->first(ImportRunKind::Full)?->key());
});

it('refuses a phase registered wrongly', function (string $case, string $message): void {
    match ($case) {
        'not a phase' => app()->tag([stdClass::class], ImportPhases::TAG),
        'malformed key' => importPhase(phasesStep('Fixture_Things', 777)),
        'one key twice' => importPhase(phasesStep('fixture-twin', 777), phasesStep('fixture-twin', 778)),
        'one order twice' => importPhase(phasesStep('fixture-first', 777), phasesStep('fixture-second', 777)),
        'no order' => importPhase(phasesStep('fixture-things', 0)),
        default => importPhase(new class extends PeoplePhase implements StepPhase
        {
            public function run(ImportContext $context, SourceReader $reader, ?string $cursor): StepResult
            {
                return StepResult::done();
            }
        }),
    };

    expect(fn () => app(ImportPhases::class)->all())->toThrow(LogicException::class, $message);
})->with([
    'something that isn\'t a phase' => ['not a phase', 'Registered as an import phase, but stdClass isn’t one.'],
    'a key that isn\'t lower-case words and hyphens' => ['malformed key', 'Import phase [Fixture_Things] needs a key of lower-case words joined by hyphens.'],
    'two phases with one key' => ['one key twice', 'Two import phases have the key [fixture-twin].'],
    'two phases with one order' => ['one order twice', 'Import phases [fixture-first] and [fixture-second] share the order 777.'],
    'an order of 0' => ['no order', 'Import phase [fixture-things] needs an order above 0.'],
    'a list phase that is a step too' => ['both', 'Import phase [fixture-people] is a list phase or a step, never both.'],
]);

it('refuses what a phase could build wrongly', function (string $case, string $message): void {
    $build = match ($case) {
        'list name' => fn () => new ImportList('Users'),
        'list limit' => fn () => new ImportList('users', [], 0),
        'object type' => fn () => SourceRef::of('Post_Type', 4),
        'object id' => fn () => new SourceRef('user', ''),
        'code' => fn () => new ImportMessage('Removed', 'Removed [vc_row].'),
        'words' => fn () => new ImportMessage('shortcode.removed', ' '),
        'context' => fn () => new ImportMessage('shortcode.removed', 'Removed.', ['found' => ['nested' => ['deep']]]),
        'teme id' => fn () => ImportResult::created('user', ''),
        'cursor' => fn () => StepResult::continueAt(''),
        default => fn () => ImportStopped::because('Address Clash', 'Two sites claim /about/.'),
    };

    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a list name that isn\'t a route\'s' => ['list name', 'An import list\'s name [Users] must be lower-case letters.'],
    'a page of nothing' => ['list limit', 'An import list\'s limit [0] must be between 1 and 2000.'],
    'an object type with capitals' => ['object type', 'An import object\'s type [Post_Type] must be lower-case words joined by hyphens.'],
    'an empty object ID' => ['object id', 'An import object\'s ID must be 1 to 500 characters.'],
    'a message code that isn\'t words joined by dots' => ['code', 'An import message\'s code [Removed] must be lower-case words joined by dots.'],
    'a message without words' => ['words', 'An import message needs words, at most 1,000 characters.'],
    'a context that isn\'t flat' => ['context', 'An import message\'s context holds at most 20 plain values: [found].'],
    'a created object without its Teme ID' => ['teme id', 'A created or updated object needs its Teme type and ID.'],
    'an empty cursor' => ['cursor', 'A step\'s cursor is 1 to 200 characters.'],
    'a stop reason with capitals' => ['stop', 'An import\'s stop reason [Address Clash] must be lower-case words joined by hyphens.'],
]);

it('hashes an item the same whatever the order of its keys, leaving out the keys a list ignores', function (): void {
    $one = ['id' => 2, 'login' => 'grace', 'roles' => ['editor'], 'meta' => ['b' => 1, 'a' => 2], 'published_posts' => 30];
    $two = ['published_posts' => 31, 'meta' => ['a' => 2, 'b' => 1], 'roles' => ['editor'], 'login' => 'grace', 'id' => 2];

    expect(ImportMap::hashOf($one, ['published_posts']))->toBe(ImportMap::hashOf($two, ['published_posts']))
        ->and(ImportMap::hashOf($one))->not->toBe(ImportMap::hashOf($two))
        ->and(ImportMap::hashOf(['roles' => ['editor', 'author']]))->not->toBe(ImportMap::hashOf(['roles' => ['author', 'editor']]));
});

it('runs only the picture pass’s phases in a picture pass, and none of them in another run (#716)', function (): void {
    $full = phasesChain(ImportRunKind::Full);
    $pass = phasesChain(ImportRunKind::MediaPass);

    expect($pass)->toBe(['outside-images', 'media-parents', 'second-pass'])
        ->and($full)->toContain('outside-images')
        ->and($full)->toContain('second-pass')
        ->and($full)->toContain('content');
});
