<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\People\Credit;
use App\Domain\People\Privacy\PeopleHolder;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyErasureInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyErasure;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\PersonalDataSubject;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'bio' => 'Mathematician'])->assignRole('editor');
    app(PathProjection::class)->syncUser($this->ada);
    $this->subject = new PersonalDataSubject('ada@example.com', (string) $this->ada->id);
    $this->article = addrPublished($this->ada, 'Harbour news');

    // Erases through the Operation with this one holder, as a request runs it, and returns its entry.
    $this->erase = function (string $email = 'ada@example.com'): array {
        app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), [PeopleHolder::class]));
        $id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('erase', $email, 'Asked by email.', false));

        while (! app(RunPrivacyErasure::class)->execute(new RunPrivacyErasureInput($id))->done);

        return PrivacyRequest::query()->findOrFail($id)->results[0];
    };
    $this->snapshot = fn (): array => [DB::table('people')->orderBy('id')->get()->all(), DB::table('content_bylines')->orderBy('content_id')->get()->all()];
});

it('exports an account\'s byline as their author page shows it, and keeps it', function (): void {
    Credited::set($this->article->id, [Credit::account((int) $this->ada->id)]);
    $page = app(PeopleHolder::class)->export($this->subject, 1);

    expect($page->done)->toBeTrue()
        ->and($page->groups)->toHaveCount(1)
        ->and($page->groups[0]->title)->toBe('Your byline')
        ->and($page->groups[0]->values)->toBe([
            'Name' => 'Ada Lovelace',
            'Author page' => 'http://localhost/author/'.$this->ada->slug.'/',
            'Biography' => 'Mathematician',
            'Items that credit you' => '1',
        ]);

    $before = ($this->snapshot)();

    expect(($this->erase)())->toMatchArray([
        'removed' => 0, 'retained' => 1, 'reasons' => ['Your name stays on what you’re credited on, as the record of who wrote it, even after the account is deleted. The site’s administrators can take it off.'],
    ])
        ->and(($this->snapshot)())->toEqual($before);
});

it('holds nothing for an account that owns nothing and is named by nothing, or for an address with no account', function (): void {
    $idle = User::factory()->create(['name' => 'Idle Writer', 'email' => 'idle@example.com'])->assignRole('editor');
    $before = ($this->snapshot)();

    expect(app(PeopleHolder::class)->export(new PersonalDataSubject('idle@example.com', (string) $idle->id), 1)->groups)->toBe([])
        ->and(app(PeopleHolder::class)->export(new PersonalDataSubject('nobody@example.com'), 1)->groups)->toBe([])
        ->and(($this->erase)('idle@example.com'))->toMatchArray(['removed' => 0, 'retained' => 0, 'reasons' => []])
        ->and(($this->erase)('nobody@example.com'))->toMatchArray(['removed' => 0, 'retained' => 0, 'reasons' => []])
        ->and(($this->snapshot)())->toEqual($before);
});

it('keeps the name of an owner of items with no bylines, and exports it, though no person exists yet', function (): void {
    $page = app(PeopleHolder::class)->export($this->subject, 1);
    $before = ($this->snapshot)();

    expect(DB::table('people')->count())->toBe(0)
        ->and($page->groups)->toHaveCount(1)
        ->and($page->groups[0]->title)->toBe('Your byline')
        ->and($page->groups[0]->values)->toBe([
            'Name' => 'Ada Lovelace',
            'Author page' => 'http://localhost/author/'.$this->ada->slug.'/',
            'Biography' => 'Mathematician',
            'Items that credit you' => '1',
        ])
        ->and(($this->erase)())->toMatchArray([
            'removed' => 0, 'retained' => 1, 'reasons' => ['Your name stays on what you’re credited on, as the record of who wrote it, even after the account is deleted. The site’s administrators can take it off.'],
        ])
        ->and(($this->snapshot)())->toEqual($before);
});

it('keeps the name of a person whose bylines were cleared but who still owns items with none', function (): void {
    Credited::set($this->article->id, [Credit::account((int) $this->ada->id)]);
    Credited::set($this->article->id, []);

    expect(DB::table('people')->where('user_id', $this->ada->id)->count())->toBe(1)
        ->and(DB::table('content_bylines')->count())->toBe(0)
        ->and(($this->erase)())->toMatchArray([
            'removed' => 0, 'retained' => 1, 'reasons' => ['Your name stays on what you’re credited on, as the record of who wrote it, even after the account is deleted. The site’s administrators can take it off.'],
        ]);
});

it('says the person goes with the account when nothing credits them any more', function (): void {
    $grace = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com'])->assignRole('editor');
    Credited::set($this->article->id, [Credit::account((int) $grace->id)]);
    Credited::set($this->article->id, []);
    $before = ($this->snapshot)();

    expect(DB::table('people')->where('user_id', $grace->id)->count())->toBe(1)
        ->and(app(PeopleHolder::class)->export(new PersonalDataSubject('grace@example.com', (string) $grace->id), 1)->groups[0]->values['Items that credit you'] ?? null)->toBe('0')
        ->and(($this->erase)('grace@example.com'))->toMatchArray(['removed' => 0, 'retained' => 1, 'reasons' => ['This goes when the account is deleted.']])
        ->and(($this->snapshot)())->toEqual($before);
});
