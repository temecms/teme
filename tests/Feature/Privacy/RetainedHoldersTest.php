<?php

declare(strict_types=1);

use App\Domain\Ai\Privacy\AiActivityHolder;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Inbox\Privacy\InboxHolder;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Privacy\PluginConsentHolder;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyErasureInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyErasure;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Security\Privacy\SecurityLogHolder;
use App\Domain\Security\SecurityLog;
use App\Domain\Settings\Privacy\SiteSettingsHolder;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Teme\Contracts\Privacy\PersonalDataSubject;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->ada = User::factory()->create(['email' => 'ada@example.com']);
    $this->grace = User::factory()->create(['email' => 'grace@example.com']);
    $this->subject = new PersonalDataSubject('ada@example.com', (string) $this->ada->id);
    $this->guest = new PersonalDataSubject('ada@example.com');

    $this->event = fn (?int $userId, string $subject, string $type = 'sign_in.failed'): bool => DB::table('security_events')->insert([
        'type' => $type, 'subject' => $subject, 'user_id' => $userId, 'ip' => '192.0.2.7', 'hour' => now()->startOfHour(),
        'counted' => true, 'count' => 3, 'context' => '{}', 'first_at' => now()->subMinutes(5), 'last_at' => now(),
    ]);
    $this->inbox = fn (User $user, string $title): bool => DB::table('inbox_items')->insert([
        'id' => strtolower((string) Str::ulid()), 'user_id' => $user->id, 'kind' => 'export.done', 'owner' => 'core',
        'body' => json_encode(['title' => $title, 'text' => 'Your export is ready.', 'link' => null]), 'read_at' => null, 'created_at' => now(),
    ]);

    // Erases through the Operation with one holder, as a request runs it, and returns its entry.
    $this->erase = function (string $holder, ?string $email = 'ada@example.com'): array {
        app()->instance(PersonalDataHolders::class, new PersonalDataHolders(app(HookDispatcher::class), [$holder]));
        $id = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('erase', (string) $email, 'Asked by email.', false));

        while (! app(RunPrivacyErasure::class)->execute(new RunPrivacyErasureInput($id))->done);

        return PrivacyRequest::query()->findOrFail($id)->results[0];
    };
    $this->snapshot = fn (): array => collect(['security_events' => 'id', 'settings' => 'group_key', 'settings_changes' => 'id', 'inbox_items' => 'id', 'ai_activity' => 'id', 'plugins' => 'id'])
        ->map(fn (string $order, string $table): array => DB::table($table)->orderBy($order)->get()->map(fn (object $row): array => (array) $row)->all())
        ->all();
});

it('exports the security log’s entries for the account and for the address, and keeps them with its reason', function (): void {
    ($this->event)($this->ada->id, SecurityLog::subject($this->ada->id, null), 'sign_in.succeeded');
    ($this->event)(null, SecurityLog::subject(null, 'ADA@example.com'));
    ($this->event)($this->grace->id, SecurityLog::subject($this->grace->id, null));
    $holder = app(SecurityLogHolder::class);

    $page = $holder->export($this->subject, 1);

    expect($page->done)->toBeTrue()
        ->and($page->groups)->toHaveCount(2)
        ->and($page->groups[0]->title)->toBe('Security event')
        ->and($page->groups[0]->values)->toMatchArray(['Event' => 'sign_in.succeeded', 'Times' => '3', 'IP address' => '192.0.2.7'])
        ->and($page->groups[0]->values)->toHaveKeys(['First', 'Last'])
        ->and($holder->export($this->guest, 1)->groups)->toHaveCount(1);

    $before = ($this->snapshot)();

    expect(($this->erase)(SecurityLogHolder::class))->toMatchArray([
        'removed' => 0, 'retained' => 2, 'reasons' => ['The security log is kept to protect the site. Its entries are deleted after 365 days.'],
    ])
        ->and(($this->snapshot)())->toEqual($before);
});

it('finds the administration email and a hold or block line equal to the address, and not a line that only holds it', function (): void {
    cmSettings(['admin_email' => 'Ada@Example.com'], 'general');
    cmSettings(['hold_list' => "ADA@example.com\nspam words\nsomeone-ada@example.com.au", 'block_list' => "ada@example.com\nbad"]);

    $page = app(SiteSettingsHolder::class)->export($this->guest, 1);

    expect($page->groups)->toHaveCount(1)
        ->and($page->groups[0]->title)->toBe('Site settings')
        ->and($page->groups[0]->values)->toBe([
            'Administration email' => 'Ada@Example.com',
            'Comment hold list' => 'ADA@example.com',
            'Comment block list' => 'ada@example.com',
        ]);

    $before = ($this->snapshot)();

    expect(($this->erase)(SiteSettingsHolder::class))->toMatchArray([
        'removed' => 0, 'retained' => 3, 'reasons' => [
            'The site\'s administration email is kept. Change it under Settings › General.',
            'The comment hold and block lists are kept, because they are used to stop abuse.',
        ],
    ])
        ->and(($this->snapshot)())->toEqual($before);
});

it('finds a settings change whose old value was the address, and not one whose hold list only held it in a longer line', function (): void {
    $change = fn (string $group, array $before, array $after): bool => DB::table('settings_changes')->insert([
        'id' => strtolower((string) Str::ulid()), 'group_key' => $group, 'changed_keys' => json_encode(array_keys($after)),
        'before' => json_encode($before), 'after' => json_encode($after), 'actor_id' => null, 'origin' => 'human', 'created_at' => now(),
    ]);
    $change('general', ['admin_email' => 'ada@example.com'], ['admin_email' => 'office@example.com']);
    $change('discussion', ['hold_list' => ''], ['hold_list' => "not-ada@example.com\nwords"]);
    $change('reading', ['note' => 'ada@example.com'], ['note' => '']);

    $page = app(SiteSettingsHolder::class)->export($this->guest, 1);

    expect($page->groups)->toHaveCount(1)
        ->and($page->groups[0]->title)->toBe('Settings change')
        ->and(array_keys($page->groups[0]->values))->toBe(['Date', 'Setting'])
        ->and($page->groups[0]->values['Setting'])->toBe('Administration email')
        ->and(($this->erase)(SiteSettingsHolder::class))->toMatchArray([
            'retained' => 1, 'reasons' => ['The history of settings changes is kept, as the record of who changed what.'],
        ]);
});

it('exports the account’s inbox and keeps it', function (): void {
    ($this->inbox)($this->ada, 'Export ready');
    ($this->inbox)($this->grace, 'Not hers');

    $page = app(InboxHolder::class)->export($this->subject, 1);

    expect($page->groups)->toHaveCount(1)
        ->and($page->groups[0]->title)->toBe('Inbox item')
        ->and($page->groups[0]->values)->toMatchArray(['Title' => 'Export ready', 'Text' => 'Your export is ready.', 'Read' => 'No'])
        ->and($page->groups[0]->values)->toHaveKey('Received');

    $before = ($this->snapshot)();

    expect(($this->erase)(InboxHolder::class))->toMatchArray(['removed' => 0, 'retained' => 1, 'reasons' => ['This goes when the account is deleted.']])
        ->and(($this->snapshot)())->toEqual($before);
});

it('exports the account’s AI requests by their figures, never a prompt, and keeps them', function (): void {
    actvRow($this->ada);
    actvRow($this->grace);

    $page = app(AiActivityHolder::class)->export($this->subject, 1);

    expect($page->groups)->toHaveCount(1)
        ->and($page->groups[0]->title)->toBe('AI request')
        ->and($page->groups[0]->values)->toMatchArray([
            'Task' => 'tidy', 'Variant' => 'shorter', 'Provider' => 'fake', 'Model' => 'fake-text', 'Prompt' => 'tidy', 'Prompt version' => '1',
            'Used on' => 'content', 'Outcome' => 'suggested', 'Input tokens' => '1200', 'Output tokens' => '80',
            'Cost in millionths' => '1400', 'Time taken in milliseconds' => '2300',
        ])
        ->and($page->groups[0]->values)->toHaveKeys(['Date', 'Prompt hash', 'Used on ID', 'Cached tokens read', 'Cached tokens written'])
        ->and($page->groups[0]->values)->not->toHaveKeys(['id', 'user_id', 'model', 'input_tokens']);

    $before = ($this->snapshot)();

    expect(($this->erase)(AiActivityHolder::class))->toMatchArray(['removed' => 0, 'retained' => 1, 'reasons' => ['This is unlinked from the account when the account is deleted.']])
        ->and(($this->snapshot)())->toEqual($before);
});

it('exports only the AI columns it names, never one added later', function (): void {
    DB::statement("ALTER TABLE ai_activity ADD COLUMN later_column text DEFAULT 'not exported'");
    actvRow($this->ada);

    $values = app(AiActivityHolder::class)->export($this->subject, 1)->groups[0]->values;

    expect($values)->not->toHaveKey('later_column')
        ->and($values)->not->toContain('not exported');
});

it('exports the plugins the account agreed to or disabled, and keeps them', function (): void {
    PluginRecord::factory()->enabled()->create(['name' => 'acme/maps', 'code' => 'maps', 'consented_by' => $this->ada->id]);
    PluginRecord::factory()->create(['name' => 'acme/forms', 'code' => 'forms', 'state' => 'disabled', 'disabled_reason' => 'by_user', 'disabled_by' => $this->ada->id, 'disabled_at' => now(), 'consented_by' => $this->grace->id]);

    $page = app(PluginConsentHolder::class)->export($this->subject, 1);

    expect(array_map(fn ($group): array => [$group->title, $group->values['Plugin'], $group->values['What you did']], $page->groups))->toEqualCanonicalizing([
        ['Plugin', 'acme/maps', 'Agreed to enable it'],
        ['Plugin', 'acme/forms', 'Disabled it'],
    ]);

    $before = ($this->snapshot)();

    expect(($this->erase)(PluginConsentHolder::class))->toMatchArray(['removed' => 0, 'retained' => 2, 'reasons' => ['This is unlinked from the account when the account is deleted.']])
        ->and(($this->snapshot)())->toEqual($before);
});

it('gives an address with no account only what matches by address', function (string $holder): void {
    ($this->event)($this->ada->id, SecurityLog::subject($this->ada->id, null));
    ($this->inbox)($this->ada, 'Export ready');
    actvRow($this->ada);
    PluginRecord::factory()->enabled()->create(['consented_by' => $this->ada->id]);

    /** @var PersonalDataHolder $instance */
    $instance = app($holder);

    expect($instance->export($this->guest, 1)->groups)->toBe([])
        ->and($instance->erase($this->guest, 1)->retained)->toBe(0);
})->with([SecurityLogHolder::class, InboxHolder::class, AiActivityHolder::class, PluginConsentHolder::class, SiteSettingsHolder::class]);
