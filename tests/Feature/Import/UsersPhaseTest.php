<?php

declare(strict_types=1);

use App\Domain\Addresses\Models\Path;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Users\UsersPhase;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Fixtures\Import\FakeCompanion;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->companion = importFake();
    $this->source = importSource();
});

/**
 * One WordPress account as the companion sends it: an author with
 * WordPress's phpass hash of "correct horse battery staple", but for $fields.
 *
 * @param  array<string, mixed>  $fields
 *
 * @return array<string, mixed>
 */
function iupAccount(int $id, array $fields = []): array
{
    return [
        'id' => $id, 'login' => "writer{$id}", 'email' => "writer{$id}@old.example", 'nicename' => "writer{$id}",
        'display_name' => "Writer {$id}", 'first_name' => '', 'last_name' => '', 'description' => '', 'url' => '',
        'registered' => '2020-01-02 03:04:05', 'roles' => ['author'], 'password_hash' => '$P$BTeme1234mc6/ctael236960/qKEKM1',
        'published_posts' => 0,
        ...$fields,
    ];
}

/**
 * The users phase alone, run as the operator to its end over these accounts.
 *
 * @param  list<array<string, mixed>>  $accounts
 * @param  array<string, mixed>  $choices
 */
function iupRun(FakeCompanion $companion, ImportSource $source, array $accounts, array $choices = []): ImportRun
{
    $companion->items('users', $accounts);
    importPhaseAlone(app(UsersPhase::class));

    return importRun($source, choices: $choices);
}

function iupRow(ImportSource $source, int $id): ImportMapEntry
{
    return ImportMapEntry::query()->where('source_id', $source->id)->where('object_type', 'user')->where('object_id', (string) $id)->firstOrFail();
}

function iupPerson(ImportSource $source, int $id): User
{
    return User::query()->findOrFail(iupRow($source, $id)->teme_id);
}

/**
 * @return list<string>
 */
function iupCodes(ImportSource $source, int $id): array
{
    return ImportMessageRecord::query()->where('map_id', iupRow($source, $id)->id)->orderBy('code')->pluck('code')->all();
}

function iupWords(ImportSource $source, int $id, string $code): string
{
    return (string) ImportMessageRecord::query()->where('map_id', iupRow($source, $id)->id)->where('code', $code)->value('message');
}

function iupStored(User $person): string
{
    return (string) DB::table('users')->where('id', $person->id)->value('password');
}

it('imports each writing account as a person with their WordPress role', function (): void {
    $run = iupRun($this->companion, $this->source, [
        iupAccount(1, ['roles' => ['administrator']]),
        iupAccount(2, ['roles' => ['editor']]),
        iupAccount(3, ['roles' => ['author']]),
        iupAccount(4, ['roles' => ['contributor']]),
    ]);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($run->created_count)->toBe(4)
        ->and(array_map(fn (int $id): array => iupPerson($this->source, $id)->getRoleNames()->all(), [1, 2, 3, 4]))->toBe([['administrator'], ['editor'], ['author'], ['contributor']])
        ->and(iupRow($this->source, 2)->teme_type)->toBe('user')
        ->and(iupCodes($this->source, 2))->toBe([]);
});

it('brings a subscriber with published posts across as a contributor with their password, and leaves one without behind (I-2998)', function (): void {
    $run = iupRun($this->companion, $this->source, [
        iupAccount(5, ['roles' => ['subscriber']]),
        iupAccount(6, ['roles' => ['subscriber'], 'published_posts' => 1]),
        iupAccount(7, ['roles' => ['subscriber'], 'published_posts' => 3]),
    ]);
    $writer = iupPerson($this->source, 7);

    expect($run->created_count)->toBe(2)
        ->and($run->skipped_count)->toBe(1)
        ->and(iupRow($this->source, 5)->teme_id)->toBeNull()
        ->and(iupCodes($this->source, 5))->toBe(['user.subscriber'])
        ->and(iupPerson($this->source, 6)->getRoleNames()->all())->toBe(['contributor'])
        ->and($writer->getRoleNames()->all())->toBe(['contributor'])
        ->and(Hash::check('correct horse battery staple', iupStored($writer)))->toBeTrue()
        ->and(iupCodes($this->source, 7))->toBe(['user.subscriber-with-posts'])
        ->and(ImportMessageRecord::query()->where('map_id', iupRow($this->source, 7)->id)->sole()->level->value)->toBe('warning')
        ->and(iupWords($this->source, 6, 'user.subscriber-with-posts'))->toBe('A subscriber with 1 published post: brought across as a contributor, so their post keeps their name. Change their role in People if they should have more, or less.')
        ->and(iupWords($this->source, 7, 'user.subscriber-with-posts'))->toBe('A subscriber with 3 published posts: brought across as a contributor, so their posts keep their name. Change their role in People if they should have more, or less.');
});

it('places Yoast SEO\'s two roles as editors, saying so', function (): void {
    iupRun($this->companion, $this->source, [
        iupAccount(8, ['roles' => ['wpseo_manager']]),
        iupAccount(9, ['roles' => ['wpseo_editor']]),
    ]);

    expect(iupPerson($this->source, 8)->getRoleNames()->all())->toBe(['editor'])
        ->and(iupPerson($this->source, 9)->getRoleNames()->all())->toBe(['editor'])
        ->and(iupWords($this->source, 8, 'user.custom-role'))->toBe('Their WordPress role wpseo_manager isn’t one of Teme’s, so they’re an Editor here.');
});

it('places custom roles where the run\'s choices put them, and leaves behind those they leave', function (): void {
    iupRun($this->companion, $this->source, [
        iupAccount(10, ['roles' => ['shop_manager']]),
        iupAccount(11, ['roles' => ['customer']]),
    ], ['roles' => ['shop_manager' => 'author', 'customer' => 'none']]);

    expect(iupPerson($this->source, 10)->getRoleNames()->all())->toBe(['author'])
        ->and(iupRow($this->source, 11)->outcome)->toBe(ImportOutcome::Skipped)
        ->and(iupWords($this->source, 11, 'user.role-left'))->toBe('The import’s choices leave their WordPress role customer behind, so they weren’t imported.');
});

it('fails a person whose only role nobody placed, and imports them once WordPress gives them a role Teme knows', function (): void {
    $first = iupRun($this->companion, $this->source, [iupAccount(12, ['roles' => ['customer']])]);
    $failed = iupRow($this->source, 12);
    $words = iupWords($this->source, 12, 'user.role-unknown');

    // A catch-up keeps the first run's choices, so what changes is the person's role on WordPress.
    $second = iupRun($this->companion, $this->source, [iupAccount(12, ['roles' => ['customer', 'author']])]);

    expect($first->failed_count)->toBe(1)
        ->and($failed->outcome)->toBe(ImportOutcome::Failed)
        ->and($words)->toBe('Their WordPress role customer isn’t one Teme knows, and this import’s choices don’t place it, so they weren’t imported. Give them one of WordPress’s own roles there and catch up, or invite them in People.')
        ->and($second->created_count)->toBe(1)
        ->and(iupPerson($this->source, 12)->getRoleNames()->all())->toBe(['author']);
});

it('never raises a person for a role nobody placed beside one Teme knows, and says so', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(13, ['roles' => ['author', 'customer']])]);

    expect(iupPerson($this->source, 13)->getRoleNames()->all())->toBe(['author'])
        ->and(iupWords($this->source, 13, 'user.role-skipped'))->toBe('Teme doesn’t know their WordPress role customer, so they’re an Author here, from their other role.');
});

it('makes an account whose email address a Teme person has into that person, writing nothing to them', function (): void {
    $ada = User::factory()->create(['name' => 'Ada at Teme', 'email' => 'ada@old.example']);
    $ada->assignRole('administrator');
    $teme = Hash::make('her Teme password');
    DB::table('users')->where('id', $ada->id)->update(['password' => $teme]);

    $run = iupRun($this->companion, $this->source, [iupAccount(1, ['email' => ' Ada@Old.Example ', 'display_name' => 'Ada Lovelace', 'roles' => ['author']])]);
    $row = iupRow($this->source, 1);

    expect(User::query()->count())->toBe(1)
        ->and($run->updated_count)->toBe(1)
        ->and([$row->teme_id, $row->outcome, $row->facts['matched'], $row->facts['nicename']])->toBe([(string) $ada->id, ImportOutcome::Updated, true, 'writer1'])
        ->and($ada->refresh()->name)->toBe('Ada at Teme')
        ->and($ada->getRoleNames()->all())->toBe(['administrator'])
        ->and(iupStored($ada))->toBe($teme)
        ->and(iupCodes($this->source, 1))->toBe(['user.matched']);
});

it('fails an account with no email address, and the second of two WordPress accounts that share an email address, whatever its case', function (): void {
    $run = iupRun($this->companion, $this->source, [
        iupAccount(1, ['email' => 'twin@old.example']),
        iupAccount(2, ['email' => 'Twin@old.example']),
        iupAccount(3, ['email' => '']),
    ]);

    expect(User::query()->count())->toBe(1)
        ->and($run->failed_count)->toBe(2)
        ->and(iupRow($this->source, 1)->outcome)->toBe(ImportOutcome::Created)
        ->and(iupCodes($this->source, 2))->toBe(['user.email-shared'])
        ->and(iupCodes($this->source, 3))->toBe(['user.no-email']);
});

it('keeps every hash Teme can check, and gives the rest a password of Teme\'s own to reset', function (): void {
    $hashes = [
        1 => '$P$BTeme1234mc6/ctael236960/qKEKM1',
        2 => '$wp$2y$04$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C',
        3 => '9cc2ae8a1ba7a93da39b46fc1019c481',
        4 => password_hash('correct horse battery staple', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
        5 => '$1$Teme1234$abcdefghijklmnopqrstuv',
        6 => '',
        7 => password_hash('correct horse battery staple', PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 1, 'threads' => 1]),
    ];

    iupRun($this->companion, $this->source, array_map(fn (int $id): array => iupAccount($id, ['password_hash' => $hashes[$id]]), array_keys($hashes)));

    foreach ([1, 2, 3, 4] as $id) {
        $person = iupPerson($this->source, $id);

        expect(iupStored($person))->toBe($hashes[$id])
            ->and(Hash::check('correct horse battery staple', iupStored($person)))->toBeTrue()
            ->and(iupCodes($this->source, $id))->toBe([]);
    }

    foreach ([5, 6, 7] as $id) {
        $person = iupPerson($this->source, $id);

        expect(iupStored($person))->toStartWith('$argon2id$')->not->toBe($hashes[$id])
            ->and(Hash::check('correct horse battery staple', iupStored($person)))->toBeFalse()
            ->and(iupCodes($this->source, $id))->toBe(['user.password-reset']);
    }
});

it('never keeps an Argon2id hash whose costs PHP can not read, at production\'s costs too', function (): void {
    // Without $v=, password_get_info() reports PHP's defaults, which are Teme's own; libargon2 would run t=100000.
    config(['hashing.argon' => ['memory' => 65536, 'time' => 4, 'threads' => 1]]);

    iupRun($this->companion, $this->source, [iupAccount(1, ['password_hash' => '$argon2id$m=65536,t=100000,p=1$c2FsdHNhbHQ$aGFzaGhhc2hoYXNoaGFzaA'])]);

    expect(iupStored(iupPerson($this->source, 1)))->not->toBe('$argon2id$m=65536,t=100000,p=1$c2FsdHNhbHQ$aGFzaGhhc2hoYXNoaGFzaA')
        ->and(iupCodes($this->source, 1))->toBe(['user.password-reset'])
        ->and(iupRow($this->source, 1)->facts['reset'])->toBeTrue();
});

it('gives each person their WordPress name, address, biography and joining date', function (): void {
    iupRun($this->companion, $this->source, [
        iupAccount(1, ['display_name' => 'Grace &amp; Co', 'nicename' => 'grace', 'description' => '<p>Writes <a href="https://old.example/ships/">about</a> ships &amp; the sea.</p>', 'registered' => '2020-06-11 14:30:00']),
        iupAccount(2, ['display_name' => '', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'nicename' => 'ada', 'registered' => '0000-00-00 00:00:00']),
        iupAccount(3, ['display_name' => 'Fish &amp;amp; Chips', 'nicename' => 'fish']),
    ]);
    $grace = iupPerson($this->source, 1);

    expect([$grace->name, $grace->slug, $grace->bio])->toBe(['Grace & Co', 'grace', 'Writes about ships & the sea.'])
        ->and(DB::table('users')->where('id', $grace->id)->value('created_at'))->toBe('2020-06-11 14:30:00')
        ->and($grace->email_verified_at)->not->toBeNull()
        ->and(Path::query()->where('target_type', 'user')->where('target_id', (string) $grace->id)->where('is_current', true)->value('path'))->toBe('author/grace')
        ->and(iupPerson($this->source, 2)->name)->toBe('Ada Lovelace')
        ->and(iupPerson($this->source, 3)->name)->toBe('Fish &amp; Chips')
        ->and(iupCodes($this->source, 1))->toBe([]);
});

it('cuts a biography longer than Teme keeps, saying so', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(1, ['description' => str_repeat('Ships. ', 200)])]);

    expect(mb_strlen((string) iupPerson($this->source, 1)->bio))->toBeLessThanOrEqual(1000)->toBeGreaterThan(990)
        ->and(iupCodes($this->source, 1))->toBe(['user.bio-shortened']);
});

it('makes an author address Teme can use from one it can not, saying so', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(1, ['nicename' => 'night_owl'])]);

    expect(iupPerson($this->source, 1)->slug)->toBe('night-owl')
        ->and(iupWords($this->source, 1, 'user.slug-changed'))->toBe('Their author address on WordPress ended in night_owl, which Teme can’t give them here, so theirs ends in night-owl.');
});

it('gives a WordPress author with posts the next free author address when a Teme person has theirs (#704)', function (): void {
    User::factory()->create(['name' => 'Grace', 'email' => 'grace@teme.example']);

    $run = iupRun($this->companion, $this->source, [iupAccount(1, ['nicename' => 'grace', 'display_name' => 'Grace Hopper', 'published_posts' => 4])]);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(iupPerson($this->source, 1)->slug)->toBe('grace-2')
        ->and(iupWords($this->source, 1, 'user.slug-changed'))->toBe('Their author address on WordPress ended in grace, which Teme can’t give them here, so theirs ends in grace-2.')
        ->and(User::query()->count())->toBe(2);
});

it('gives a WordPress person without posts the next free author address instead, in their own script', function (): void {
    User::factory()->create(['name' => 'José', 'email' => 'jose@teme.example', 'slug' => 'josé']);

    $run = iupRun($this->companion, $this->source, [iupAccount(1, ['nicename' => 'jos%c3%a9', 'published_posts' => 0])]);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(iupPerson($this->source, 1)->slug)->toBe('josé-2')
        ->and(iupCodes($this->source, 1))->toBe(['user.slug-changed']);
});

it('brings a person who has not signed in here up to date on a later run', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(1)]);

    $second = iupRun($this->companion, $this->source, [iupAccount(1, ['display_name' => 'Writer One', 'password_hash' => '$wp$2y$04$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C'])]);
    $person = iupPerson($this->source, 1);

    expect($second->updated_count)->toBe(1)
        ->and($person->name)->toBe('Writer One')
        ->and(iupStored($person))->toBe('$wp$2y$04$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C');
});

it('never replaces the password of a person who has signed in here since', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(1)]);
    $person = iupPerson($this->source, 1);
    $teme = Hash::make('their Teme password');
    DB::table('users')->where('id', $person->id)->update(['password' => $teme]);

    iupRun($this->companion, $this->source, [iupAccount(1, ['display_name' => 'Someone Else', 'password_hash' => '$wp$2y$04$5aAxxvIskoUury1rVRrTPeh8TStPa0/V0C4uO5YAdVIvZCIbKRN5C'])]);

    expect(iupStored($person))->toBe($teme)
        ->and($person->refresh()->name)->toBe('Writer 1');
});

it('suspends a person who has not signed in here when WordPress gives them no role, and never lifts it', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['author']])]);
    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['subscriber']])]);
    $suspended = iupPerson($this->source, 1);
    $words = iupWords($this->source, 1, 'user.suspended-by-wordpress');

    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['author']])]);

    expect($suspended->suspended_at)->not->toBeNull()
        ->and($suspended->getRoleNames()->all())->toBe(['author'])
        ->and($words)->toBe('WordPress gives them no role Teme imports now (subscriber), so Teme suspended their account here. Reactivate it in People if they still need to sign in.')
        ->and(DB::table('security_events')->where('type', 'user.suspended')->where('user_id', $suspended->id)->count())->toBe(1)
        ->and(iupPerson($this->source, 1)->suspended_at)->not->toBeNull()
        ->and(iupWords($this->source, 1, 'user.still-suspended'))->toBe('WordPress gives them a role again (author), but their account here stays suspended until someone reactivates it in People.');
});

it('only reports WordPress taking every role away once the person has signed in here', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['author']])]);
    DB::table('users')->where('id', iupRow($this->source, 1)->teme_id)->update(['password' => Hash::make('their Teme password')]);

    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['subscriber']])]);

    expect(iupPerson($this->source, 1)->suspended_at)->toBeNull()
        ->and(iupWords($this->source, 1, 'user.role-differs'))->toBe('Their role on WordPress (subscriber) isn’t the one they have here; Teme kept them as an Author. Change it in People if they need another.');
});

it('never suspends the last active administrator, reporting it instead', function (): void {
    // The operator runs this import, so the one administrator is the person imported.
    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['administrator']])]);
    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => []])]);

    expect(iupPerson($this->source, 1)->suspended_at)->toBeNull()
        ->and(iupCodes($this->source, 1))->toBe(['user.role-differs']);
});

it('lowers the role of a person who has not signed in here when WordPress lowers theirs, saying so', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['editor']])]);
    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['author']])]);
    $person = iupPerson($this->source, 1);

    expect($person->getRoleNames()->all())->toBe(['author'])
        ->and(iupWords($this->source, 1, 'user.role-lowered'))->toBe('Their role on WordPress (author) is lower than the one they had here, so they’re an Author now.')
        ->and(DB::table('security_events')->where('type', 'user.role_changed')->where('user_id', $person->id)->count())->toBe(1);
});

it('never raises a person\'s role when WordPress raises theirs', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['author']])]);
    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['editor']])]);

    expect(iupPerson($this->source, 1)->getRoleNames()->all())->toBe(['author'])
        ->and(iupCodes($this->source, 1))->toBe(['user.role-differs']);
});

it('follows no role from WordPress once the person has signed in here', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['editor']])]);
    $person = iupPerson($this->source, 1);
    DB::table('users')->where('id', $person->id)->update(['password' => Hash::make('their Teme password')]);

    iupRun($this->companion, $this->source, [iupAccount(1, ['roles' => ['author']])]);

    expect(iupPerson($this->source, 1)->getRoleNames()->all())->toBe(['editor'])
        ->and(iupCodes($this->source, 1))->toBe(['user.role-differs']);
});

it('says again on a later run what the first run said of a person', function (): void {
    $account = ['nicename' => 'night_owl', 'password_hash' => 'nonsense', 'roles' => ['wpseo_manager']];
    iupRun($this->companion, $this->source, [iupAccount(1, $account)]);
    $first = iupCodes($this->source, 1);

    iupRun($this->companion, $this->source, [iupAccount(1, [...$account, 'display_name' => 'Writer One'])]);

    expect($first)->toBe(['user.custom-role', 'user.password-reset', 'user.slug-changed'])
        ->and(iupCodes($this->source, 1))->toBe($first)
        ->and(iupRow($this->source, 1)->facts['reset'])->toBeTrue();
});

it('leaves a person deleted in Teme since deleted', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(1)]);
    User::query()->whereKey(iupRow($this->source, 1)->teme_id)->delete();

    iupRun($this->companion, $this->source, [iupAccount(1, ['display_name' => 'Writer One'])]);

    expect(User::query()->count())->toBe(0)
        ->and(iupRow($this->source, 1)->outcome)->toBe(ImportOutcome::Skipped)
        ->and(iupCodes($this->source, 1))->toBe(['user.deleted-here']);
});

it('keeps email addresses and password hashes out of every message', function (): void {
    User::factory()->create(['email' => 'matched@old.example']);

    iupRun($this->companion, $this->source, [
        iupAccount(1, ['email' => 'matched@old.example']),
        iupAccount(2, ['roles' => ['customer']]),
        iupAccount(3, ['password_hash' => 'nonsense']),
        iupAccount(4, ['email' => 'Matched@old.example']),
    ]);
    $said = ImportMessageRecord::query()->get()->map(fn (ImportMessageRecord $record): string => $record->message.' '.json_encode($record->context))->implode(' ');

    expect(ImportMessageRecord::query()->count())->toBe(4)
        ->and($said)->not->toContain('@')
        ->and($said)->not->toContain('$P$')
        ->and($said)->not->toContain('nonsense');
});

it('registers the users phase at order 200, for every kind of run', function (): void {
    $phase = app(ImportPhases::class)->find('users');

    expect($phase)->toBeInstanceOf(UsersPhase::class)
        ->and($phase?->order())->toBe(200)
        ->and($phase?->runsIn(ImportRunKind::CatchUp))->toBeTrue();
});

it('brings a subscriber across on the run after their first post, and keeps them a contributor, saying so again, on later runs (I-2998)', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(6, ['roles' => ['subscriber']])]);
    $skipped = iupRow($this->source, 6)->outcome;

    iupRun($this->companion, $this->source, [iupAccount(6, ['roles' => ['subscriber'], 'published_posts' => 1])]);
    $created = iupRow($this->source, 6)->outcome;

    $third = iupRun($this->companion, $this->source, [iupAccount(6, ['roles' => ['subscriber'], 'published_posts' => 2])]);
    $person = iupPerson($this->source, 6);

    expect($skipped)->toBe(ImportOutcome::Skipped)
        ->and($created)->toBe(ImportOutcome::Created)
        ->and($third->updated_count)->toBe(1)
        ->and($person->suspended_at)->toBeNull()
        ->and($person->getRoleNames()->all())->toBe(['contributor'])
        ->and(iupCodes($this->source, 6))->toBe(['user.subscriber-with-posts'])
        ->and(iupWords($this->source, 6, 'user.subscriber-with-posts'))->toStartWith('A subscriber with 2 published posts: ');
});

it('never brings a subscriber with posts across beside a role nobody placed, failing them as before', function (): void {
    iupRun($this->companion, $this->source, [iupAccount(8, ['roles' => ['subscriber', 'shop_manager'], 'published_posts' => 2])]);

    expect(User::query()->count())->toBe(0)
        ->and(iupRow($this->source, 8)->outcome)->toBe(ImportOutcome::Failed)
        ->and(iupCodes($this->source, 8))->toBe(['user.role-unknown']);
});

it('notes the run that first matched an account to a Teme person, and keeps it on later runs (I-2998)', function (): void {
    User::factory()->create(['email' => 'writer1@old.example'])->assignRole('editor');

    $first = iupRun($this->companion, $this->source, [iupAccount(1, ['published_posts' => 1])]);
    $second = iupRun($this->companion, $this->source, [iupAccount(1, ['published_posts' => 2])]);
    $row = iupRow($this->source, 1);

    expect($row->run_id)->toBe($second->id)
        ->and($row->facts['matched'])->toBeTrue()
        ->and($row->facts['matched_in'])->toBe($first->id);
});
