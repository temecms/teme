<?php

declare(strict_types=1);

use App\Domain\Site\Document\Placement;
use App\Domain\SiteCode\Models\SiteCodeEntry;
use App\Domain\SiteCode\Operations\DeleteSiteCode;
use App\Domain\SiteCode\Operations\Input\DeleteSiteCodeInput;
use App\Domain\SiteCode\Operations\Input\SaveSiteCodeInput;
use App\Domain\SiteCode\Operations\Input\SwitchSiteCodeInput;
use App\Domain\SiteCode\Operations\SaveSiteCode;
use App\Domain\SiteCode\Operations\SwitchSiteCode;
use App\Domain\SiteCode\SiteCode;
use App\Domain\Support\Security\ElevationRequiredException;
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
    $this->analytics = '<script defer data-site="ledger" src="https://stats.example.org/script.js"></script>';

    // Each change, as one person would make it to the entry with this ID.
    $this->change = fn (string $change, string $entryId, User $actor): mixed => match ($change) {
        'adding' => app(SaveSiteCode::class)->execute(new SaveSiteCodeInput(null, 'Chat widget', 'body-end', '<script async src="https://chat.example.net/widget.js"></script>', true, (string) $actor->id)),
        'changing' => app(SaveSiteCode::class)->execute(new SaveSiteCodeInput($entryId, 'Renamed', 'head', '<script></script>', true, (string) $actor->id)),
        'switching' => app(SwitchSiteCode::class)->execute(new SwitchSiteCodeInput($entryId, false, (string) $actor->id)),
        default => app(DeleteSiteCode::class)->execute(new DeleteSiteCodeInput($entryId, (string) $actor->id)),
    };
});

it('has the document shell\'s three placements and the place a card puts code, and words for each', function (): void {
    expect(array_keys(SiteCode::PLACES))->toBe([...array_map(fn (Placement $placement): string => $placement->value, Placement::cases()), SiteCode::PLACED])
        ->and(array_column(SiteCode::PLACES, 'label'))->toBe(['Head', 'Start of the body', 'End of the body', 'Placed with a card']);
});

it('keeps code an administrator places with a card, and draws it on no page by itself (I-3127)', function (): void {
    $entry = scAdd($this->admin, 'Sidebar ad', 'placed', '<div data-test="placed-ad">Ad</div>');
    cmItem(User::factory()->create()->assignRole('author'), 'Harbour news');

    expect($entry->refresh()->only(['name', 'placement', 'enabled']))->toBe(['name' => 'Sidebar ad', 'placement' => 'placed', 'enabled' => true])
        ->and(scLog()[0]['context']['after_placement'])->toBe('placed')
        ->and((string) addrGet('/harbour-news/')->assertOk()->getContent())->not->toContain('data-test="placed-ad"');
});

it('writes nothing when an editor tries to place code with a card', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => scElevated(fn (): mixed => app(SaveSiteCode::class)->execute(new SaveSiteCodeInput(null, 'Sidebar ad', 'placed', '<div>Ad</div>', true, (string) $editor->id))))
        ->toThrow(AuthorizationException::class, 'Not permitted to change site code.')
        ->and(DB::table('site_code_entries')->count())->toBe(0)
        ->and(scLog())->toBe([]);
});

it('adds an entry, keeping the code exactly as it came, and logs it with nothing before (I-865)', function (): void {
    $pasted = "<script>window.dataLayer = window.dataLayer || []; if (a < b && c > d) { go('\"x\"'); }</script>\r\n<img src=\"https://px.example.net/p.gif\" onload=\"track(this)\" alt=\"\">\n<!-- Café ✓ --></body>";

    $entry = scAdd($this->admin, '  Visitor analytics ', 'body-end', $pasted);

    expect($entry->refresh()->only(['name', 'placement', 'code', 'enabled', 'updated_by']))
        ->toBe(['name' => 'Visitor analytics', 'placement' => 'body-end', 'code' => $pasted, 'enabled' => true, 'updated_by' => $this->admin->id])
        ->and(scLog())->toBe([scEvent('site_code.added', $this->admin->id, [
            'entry' => $entry->id,
            'before_name' => null, 'before_placement' => null, 'before_on' => null, 'before_code' => null, 'before_origins' => null,
            'after_name' => 'Visitor analytics', 'after_placement' => 'body-end', 'after_on' => true, 'after_code' => $pasted, 'after_origins' => '',
        ])]);
});

it('changes an entry, and logs what it was and what it became', function (): void {
    $entry = scAdd($this->admin);

    $result = scElevated(fn (): array => app(SaveSiteCode::class)->execute(new SaveSiteCodeInput($entry->id, 'Stats', 'body-start', '<script src="https://stats.example.org/v2.js"></script>', false, (string) $this->admin->id)));

    expect($result)->toBe(['id' => $entry->id, 'added' => false, 'changed' => true])
        ->and($entry->refresh()->only(['name', 'placement', 'code', 'enabled']))
        ->toBe(['name' => 'Stats', 'placement' => 'body-start', 'code' => '<script src="https://stats.example.org/v2.js"></script>', 'enabled' => false])
        ->and(scLog()[1])->toBe(scEvent('site_code.changed', $this->admin->id, [
            'entry' => $entry->id,
            'before_name' => 'Visitor analytics', 'before_placement' => 'head', 'before_on' => true, 'before_code' => $this->analytics, 'before_origins' => '',
            'after_name' => 'Stats', 'after_placement' => 'body-start', 'after_on' => false, 'after_code' => '<script src="https://stats.example.org/v2.js"></script>', 'after_origins' => '',
        ]));
});

it('writes and logs nothing when a save changes nothing', function (): void {
    $entry = scAdd($this->admin);

    $result = scElevated(fn (): array => app(SaveSiteCode::class)->execute(new SaveSiteCodeInput($entry->id, 'Visitor analytics', 'head', $this->analytics, true, (string) $this->admin->id)));

    expect($result)->toBe(['id' => $entry->id, 'added' => false, 'changed' => false])
        ->and(count(scLog()))->toBe(1);
});

it('switches an entry off and on, logging each, and writes nothing when it already is', function (): void {
    $entry = scAdd($this->admin);
    $switch = fn (bool $on): array => scElevated(fn (): array => app(SwitchSiteCode::class)->execute(new SwitchSiteCodeInput($entry->id, $on, (string) $this->admin->id)));

    expect($switch(false))->toBe(['name' => 'Visitor analytics', 'changed' => true])
        ->and($switch(false))->toBe(['name' => 'Visitor analytics', 'changed' => false])
        ->and($switch(true))->toBe(['name' => 'Visitor analytics', 'changed' => true])
        ->and(array_column(scLog(), 'type'))->toBe(['site_code.added', 'site_code.switched_off', 'site_code.switched_on'])
        ->and([scLog()[1]['context']['before_on'], scLog()[1]['context']['after_on']])->toBe([true, false])
        ->and(scLog()[1]['context']['after_code'])->toBe($this->analytics)
        ->and($entry->refresh()->enabled)->toBeTrue();
});

it('deletes an entry, and logs the code it held', function (): void {
    $entry = scAdd($this->admin);

    $result = scElevated(fn (): array => app(DeleteSiteCode::class)->execute(new DeleteSiteCodeInput($entry->id, (string) $this->admin->id)));

    expect($result)->toBe(['name' => 'Visitor analytics', 'changed' => true])
        ->and(SiteCodeEntry::query()->count())->toBe(0)
        ->and(scLog()[1])->toBe(scEvent('site_code.deleted', $this->admin->id, [
            'entry' => $entry->id,
            'before_name' => 'Visitor analytics', 'before_placement' => 'head', 'before_on' => true, 'before_code' => $this->analytics, 'before_origins' => '',
            'after_name' => null, 'after_placement' => null, 'after_on' => null, 'after_code' => null, 'after_origins' => null,
        ]));
});

it('refuses a change to an entry deleted while it was open', function (string $change): void {
    $entry = scAdd($this->admin);
    scElevated(fn (): array => app(DeleteSiteCode::class)->execute(new DeleteSiteCodeInput($entry->id, (string) $this->admin->id)));

    expect(fn () => scElevated(fn (): mixed => ($this->change)($change, $entry->id, $this->admin)))
        ->toThrow(ValidationException::class, 'This code was deleted while you had it open.')
        ->and(count(scLog()))->toBe(2);
})->with(['changing', 'switching', 'deleting']);

it('writes nothing when authorization fails: an editor may not add, change, switch or delete site code (I-850)', function (string $change): void {
    $entry = scAdd($this->admin);
    $editor = User::factory()->create()->assignRole('editor');
    $before = DB::table('site_code_entries')->get()->all();

    expect(fn () => scElevated(fn (): mixed => ($this->change)($change, $entry->id, $editor)))
        ->toThrow(AuthorizationException::class, 'Not permitted to change site code.')
        ->and(DB::table('site_code_entries')->get()->all())->toEqual($before)
        ->and(count(scLog()))->toBe(1);
})->with(['adding', 'changing', 'switching', 'deleting']);

it('refuses every change while the host disallows site code, before anyone is asked for a password (I-866)', function (string $change): void {
    $entry = scAdd($this->admin);
    config(['teme.disallow_site_code' => true]);

    // Not elevated: were the password asked first, this would throw ElevationRequiredException.
    expect(fn () => ($this->change)($change, $entry->id, $this->admin))
        ->toThrow(AuthorizationException::class, 'The host has switched site code off, so it can’t be changed here.')
        ->and(SiteCodeEntry::query()->sole()->only(['name', 'enabled']))->toBe(['name' => 'Visitor analytics', 'enabled' => true])
        ->and(count(scLog()))->toBe(1);
})->with(['adding', 'changing', 'switching', 'deleting']);

it('asks for a fresh password confirmation before any change, and writes nothing without one (I-864)', function (string $change): void {
    $entry = scAdd($this->admin);

    expect(fn () => ($this->change)($change, $entry->id, $this->admin))->toThrow(ElevationRequiredException::class)
        ->and(SiteCodeEntry::query()->sole()->only(['name', 'enabled']))->toBe(['name' => 'Visitor analytics', 'enabled' => true])
        ->and(count(scLog()))->toBe(1);
})->with(['adding', 'changing', 'switching', 'deleting']);

it('refuses a name or code it couldn\'t keep, saying why', function (string $case, string $message): void {
    [$name, $placement, $code] = match ($case) {
        'no name' => ['   ', 'head', '<script></script>'],
        'a name too long' => [str_repeat('a', 121), 'head', '<script></script>'],
        'an unknown placement' => ['Stats', 'footer', '<script></script>'],
        'no code' => ['Stats', 'head', "  \r\n "],
        'too much code' => ['Stats', 'head', str_repeat('x', 65536)],
        'text that isn\'t UTF-8' => ['Stats', 'head', "<script>\xB1\x31</script>"],
        default => ['Stats', 'head', "<script>\0</script>"],
    };

    expect(fn () => scElevated(fn (): array => app(SaveSiteCode::class)->execute(new SaveSiteCodeInput(null, $name, $placement, $code, true, (string) $this->admin->id))))
        ->toThrow(ValidationException::class, $message)
        ->and(SiteCodeEntry::query()->count())->toBe(0)
        ->and(scLog())->toBe([]);
})->with([
    'no name' => ['no name', 'Give the code a name, so everyone can tell what it is.'],
    'a name too long' => ['a name too long', 'Keep the name to 120 characters or fewer.'],
    'an unknown placement' => ['an unknown placement', 'Choose where the code goes.'],
    'no code' => ['no code', 'Paste the code to add, or cancel.'],
    'too much code' => ['too much code', 'The code is longer than 65,535 characters, the most one entry holds.'],
    'text that isn\'t UTF-8' => ['text that isn\'t UTF-8', 'The code holds characters a page can’t carry. Paste it again from where you copied it.'],
    'a NUL' => ['a NUL', 'The code holds characters a page can’t carry. Paste it again from where you copied it.'],
]);

it('flags code that holds structured data, whatever its quotes or case, and nothing else (I-865)', function (): void {
    $flagged = fn (string $code): bool => (new SiteCodeEntry(['code' => $code]))->holdsStructuredData();

    expect($flagged('<script type="application/ld+json">{"@type":"Organization"}</script>'))->toBeTrue()
        ->and($flagged("<SCRIPT id='org' TYPE='Application/LD+JSON'>{}</SCRIPT>"))->toBeTrue()
        ->and($flagged('<script type=application/ld+json>{}</script>'))->toBeTrue()
        ->and($flagged($this->analytics))->toBeFalse()
        ->and($flagged('<script type="application/json">{"ld":"json"}</script>'))->toBeFalse();
});
